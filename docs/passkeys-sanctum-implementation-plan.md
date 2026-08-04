# Plan implementacije: Laravel Passkeys i Sanctum

## Cilj

Zadržati `laravel/passkeys` za WebAuthn kriptografiju i skladištenje credentiala, isključiti njegove session-based rute i izgraditi sopstveni `/api/v1` sloj koji izdaje Sanctum bearer tokene.

Usvojene odluke:

- Aktivacija uređaja i PIN ostaju početni i rezervni način autentifikacije.
- Passkey prijava je dozvoljena samo sa prethodno aktiviranog i pouzdanog uređaja.
- Plan obuhvata Laravel backend; Expo povezivanje dolazi nakon stabilizacije API-ja.
- Paket ostaje u `vendor/`; njegovi fajlovi se ne kopiraju niti menjaju.

> **Status implementacije:** Prvi korak iz redosleda implementacije je završen. Dodati su active-user middleware, imenovani rate limiteri i petnaestominutni PIN lockout posle pet neuspešnih pokušaja. Bezbedan activation/PIN enrollment token ostaje sledeći korak.

## Ciljna arhitektura

Zadržavamo:

- `GenerateRegistrationOptions` za pravljenje registracionih WebAuthn opcija.
- `StorePasskey` za proveru i čuvanje novog credentiala.
- `GenerateVerificationOptions` za pravljenje assertion opcija.
- `VerifyPasskey` za kriptografsku proveru credentiala.
- `DeletePasskey` za uklanjanje credentiala.
- `WebAuthn` serializer iz paketa.
- Sanctum bearer tokene za sve zaštićene API zahteve.

Ne koristimo:

- rute koje automatski registruje paket;
- paketove kontrolere;
- paketove Form Request klase koje čitaju challenge iz sesije;
- `web` guard za passkey prijavu;
- Laravel sesiju za čuvanje WebAuthn ceremonije;
- `password.confirm` middleware.

### Registracija passkeya

```text
Aktivacija uređaja
    -> postavljanje PIN-a
    -> Sanctum token
    -> potvrda PIN-a
    -> kratkotrajni management grant
    -> registration options + ceremony ID
    -> WebAuthn registracija
    -> čuvanje passkeya
```

### Passkey prijava

```text
Pouzdan device identifier
    -> verification options + ceremony ID
    -> WebAuthn assertion
    -> provera passkeya, korisnika i uređaja
    -> Sanctum token
```

## 1. Bezbednosni preduslovi

Passkey ne popravlja sistem ako napadač može da zaobiđe passkey preko slabijeg PIN toka. Sledeće izmene treba završiti pre puštanja passkey prijave u produkciju.

### 1.1 Rate limiting i PIN lockout

Definisati imenovane limitere, po mogućstvu u `app/Providers/AppServiceProvider.php`:

| Operacija | Početni limit | Ključ |
|---|---:|---|
| Aktivacija | 5/min | IP |
| Postavljanje PIN-a | 5/min | IP + uređaj |
| PIN prijava | 5/min | IP + uređaj |
| Potvrda PIN-a | 5/min | korisnik + IP |
| Passkey options | 10/min | IP + uređaj |
| Passkey verification | 5/min | IP + uređaj |
| Passkey management | 5/min | korisnik |

Pored minutnog throttlinga, beležiti neuspešne PIN pokušaje i privremeno zaključati prijavu. Četvorocifreni PIN ima samo 10.000 kombinacija, pa sam sporiji rate limit nije dovoljan kao dugoročna zaštita.

### 1.2 Status korisnika

Napraviti `app/Http/Middleware/EnsureUserIsActive.php` i zahtevati `status === 'active'` za:

- PIN prijavu;
- passkey login options i verification;
- passkey management;
- bankarske API rute.

Kada korisnik dobije status `blocked`, obrisati njegove Sanctum tokene:

```php
$user->tokens()->delete();
```

Passkey credentiali mogu ostati sačuvani kako bi ponovo radili posle administrativnog odblokiranja.

### 1.3 Bezbedno postavljanje prvog PIN-a

Trenutni `POST /set_pin` koristi `device_identifier` kao ovlašćenje. Zameniti to kratkotrajnim enrollment tokenom:

1. Uspešna aktivacija generiše najmanje 256-bitni slučajni token.
2. Server ga čuva 5-10 minuta uz `user_id` i `device_id`.
3. `POST /set_pin` zahteva enrollment token.
4. Token se atomski troši pre upisa PIN-a.
5. Ponovna upotreba ili istek vraćaju generičku grešku.

## 2. Isključivanje paketovih ruta

U `app/Providers/AppServiceProvider.php` pozvati `ignoreRoutes()` pre konfiguracije modela:

```php
public function boot(): void
{
    Passkeys::ignoreRoutes();

    Passkeys::useUserModel(User::class);
    Passkeys::usePasskeyModel(Passkey::class);
}
```

Provera:

```bash
php artisan route:list --path=passkeys -v
```

Paketove root rute, kao `/passkeys/login`, `/passkeys/confirm` i `/user/passkeys`, više ne smeju postojati.

## 3. Konfiguracija

Dodati eksplicitne produkcione vrednosti:

```dotenv
PASSKEYS_RELYING_PARTY_ID=fon-banking.duckdns.org
PASSKEYS_ALLOWED_ORIGINS=https://fon-banking.duckdns.org
PASSKEYS_USER_HANDLE_SECRET=<stabilan-slucajan-secret>
PASSKEYS_CEREMONY_TTL=120
```

Prilagoditi `config/passkeys.php` da čita ove promenljive. Bitna pravila:

- RP ID mora odgovarati domenu za koju se passkey registruje.
- Allowed origins moraju tačno odgovarati WebAuthn klijentu.
- Produkcija mora koristiti HTTPS.
- `PASSKEYS_USER_HANDLE_SECRET` mora ostati stabilan nakon prve registracije.
- Ceremony TTL treba da bude 60-120 sekundi.

Postojeća polja `guard`, `middleware`, `management_middleware` i `redirect` neće upravljati novim API rutama.

## 4. User model

U `app/Models/User.php` zadržati `PasskeyUser` i `PasskeyAuthenticatable`.

Dodati čitljivo prikazno ime:

```php
public function getPasskeyDisplayName(): string
{
    return "{$this->first_name} {$this->last_name}";
}
```

`getPasskeyUsername()` može nastaviti da koristi email adresu.

## 5. Server-side ceremony store

Napraviti:

```text
app/Services/PasskeyCeremonyStore.php
```

Servis čuva WebAuthn options server-side i klijentu vraća samo neprovidan `ceremony_id`.

### 5.1 Payload

Login ceremonija:

```json
{
  "type": "login",
  "options": "{serialized WebAuthn options}",
  "user_id": 123,
  "device_id": 45,
  "access_token_id": null,
  "created_at": "2026-07-22T10:00:00Z"
}
```

Registraciona ceremonija:

```json
{
  "type": "registration",
  "options": "{serialized WebAuthn options}",
  "user_id": 123,
  "device_id": null,
  "access_token_id": 789,
  "created_at": "2026-07-22T10:00:00Z"
}
```

### 5.2 Kreiranje

Generisati ID pomoću:

```php
bin2hex(random_bytes(32));
```

Cache ključ:

```text
passkeys:ceremony:{ceremony_id}
```

Payload čuvati kao JSON zbog `serializable_classes=false` konfiguracije.

### 5.3 Atomska jednokratna potrošnja

Servis treba da izloži operacije poput `create()` i `consume()`.

`consume()` mora:

1. Zaključati ceremony ID sa `Cache::lock(...)`.
2. Učitati payload.
3. Proveriti očekivani tip.
4. Obrisati payload.
5. Otpustiti lock.
6. Vratiti podatke pozivaocu.

Challenge se briše pre kriptografske provere credentiala. I neuspešna provera troši ceremoniju, pa korisnik mora tražiti nove options. Tako se sprečava replay i paralelna dvostruka upotreba.

## 6. PIN potvrda za passkey management

Samo posedovanje Sanctum tokena nije dovoljno za dodavanje ili brisanje passkeya. Ukradeni bearer token bi inače omogućio napadaču da registruje svoj credential.

Proširiti `POST /api/v1/auth/confirm-pin` tako da posle uspešne provere vrati:

```json
{
  "status": "success",
  "confirmation_token": "opaque-random-token",
  "expires_in": 300
}
```

Server-side grant sadrži:

```json
{
  "user_id": 123,
  "access_token_id": 789,
  "purpose": "passkeys.manage"
}
```

Grant mora biti:

- važeći najviše pet minuta;
- vezan za korisnika i trenutni Sanctum token;
- jednokratan;
- namenjen isključivo za `passkeys.manage`.

Registration options troše grant i zamenjuju ga registracionom ceremonijom. Delete endpoint direktno troši grant.

## 7. API rute

### 7.1 Javne rute

```text
POST /api/v1/auth/passkeys/login/options
POST /api/v1/auth/passkeys/login
```

Options ruta je `POST` jer prima `device_identifier`.

### 7.2 Zaštićene rute

Sve koriste `auth:sanctum`, `active-user` i odgovarajući throttle:

```text
GET    /api/v1/passkeys
POST   /api/v1/passkeys/registration/options
POST   /api/v1/passkeys
DELETE /api/v1/passkeys/{passkey}
```

### 7.3 Kontroleri

```text
app/Http/Controllers/PasskeyLoginController.php
app/Http/Controllers/PasskeyController.php
```

`PasskeyLoginController`:

- `options()`
- `store()`

`PasskeyController`:

- `index()`
- `registrationOptions()`
- `store()`
- `destroy()`

## 8. Login options endpoint

Request:

```json
{
  "device_identifier": "device-id"
}
```

Koraci:

1. Validirati `device_identifier`.
2. Pronaći Device zapis.
3. Proveriti `is_trusted`.
4. Učitati korisnika i zahtevati status `active`.
5. Proveriti da korisnik ima najmanje jedan passkey.
6. Pozvati `GenerateVerificationOptions($user)`.
7. Serijalizovati options sa `WebAuthn::toJson($options)`.
8. Sačuvati login ceremoniju vezanu za `user_id` i `device_id`.
9. Vratiti browser/native format sa `WebAuthn::toBrowserArray($options)`.

Response:

```json
{
  "ceremony_id": "...",
  "options": {
    "challenge": "...",
    "rpId": "...",
    "allowCredentials": [],
    "userVerification": "required",
    "timeout": 60000
  }
}
```

Javna greška treba da bude generička kako ne bi otkrila da li uređaj, korisnik ili passkey postoje.

## 9. Login verification endpoint

Request:

```json
{
  "ceremony_id": "...",
  "device_identifier": "device-id",
  "credential": {
    "id": "...",
    "rawId": "...",
    "type": "public-key",
    "response": {}
  }
}
```

Koraci:

1. Validirati strukturu zahteva.
2. Parsirati credential preko `WebAuthn::fromJson(..., PublicKeyCredential::class)`.
3. Atomski potrošiti login ceremoniju.
4. Proveriti da uređaj odgovara uređaju iz ceremonije.
5. Ponovo učitati Device i User iz baze.
6. Ponovo proveriti `is_trusted` i `status === 'active'`.
7. Deserijalizovati sačuvane `PublicKeyCredentialRequestOptions`.
8. Pozvati `VerifyPasskey($credential, $options, $user)`.
9. Ažurirati `device.last_login_at`.
10. Izdati Sanctum token na isti način kao PIN login.

Prosleđivanje `$user` u `VerifyPasskey` je obavezno jer eksplicitno proverava vlasništvo credentiala.

Response ostaje kompatibilan sa postojećim loginom:

```json
{
  "status": "success",
  "message": "Uspešna prijava.",
  "token": "sanctum-plain-text-token"
}
```

## 10. Registration options endpoint

Endpoint:

```text
POST /api/v1/passkeys/registration/options
```

Request:

```json
{
  "confirmation_token": "..."
}
```

Koraci:

1. Učitati autentifikovanog korisnika i trenutni Sanctum token.
2. Atomski potrošiti confirmation grant.
3. Proveriti `user_id`, `access_token_id` i purpose.
4. Pozvati `GenerateRegistrationOptions($user)`.
5. Sačuvati options kao registracionu ceremoniju vezanu za korisnika i token.
6. Vratiti `ceremony_id` i browser/native options.

## 11. Završetak registracije

Endpoint:

```text
POST /api/v1/passkeys
```

Request:

```json
{
  "ceremony_id": "...",
  "name": "Moj telefon",
  "credential": {
    "id": "...",
    "rawId": "...",
    "type": "public-key",
    "response": {}
  }
}
```

Koraci:

1. Validirati naziv i credential.
2. Parsirati credential.
3. Atomski potrošiti registration ceremoniju.
4. Proveriti `user_id` i ID trenutnog Sanctum tokena.
5. Ponovo proveriti status korisnika.
6. Deserijalizovati registration options.
7. Pozvati `StorePasskey($user, $name, $credential, $options)`.
8. Vratiti `201 Created`.

Response ne treba da izlaže credential JSON:

```json
{
  "passkey": {
    "id": 1,
    "name": "Moj telefon",
    "last_used_at": null,
    "created_at": "2026-07-22T10:00:00Z"
  }
}
```

## 12. Lista passkeyeva

Endpoint:

```text
GET /api/v1/passkeys
```

Uvek koristiti relaciju autentifikovanog korisnika:

```php
$request->user()->passkeys();
```

Vraćati samo `id`, `name`, `last_used_at` i `created_at`. Klijent nikada ne šalje `user_id`.

## 13. Brisanje passkeya

Endpoint:

```text
DELETE /api/v1/passkeys/{passkey}
```

Request:

```json
{
  "confirmation_token": "..."
}
```

Koraci:

1. Potrošiti passkey management confirmation grant.
2. Proveriti korisnika i trenutni Sanctum token.
3. Učitati passkey kroz `$request->user()->passkeys()`.
4. Pozvati `DeletePasskey($user, $passkey)`.
5. Vratiti `204 No Content`.

Poslednji passkey može biti obrisan jer PIN ostaje fallback. Globalni route model binding nije dovoljan bez provere vlasništva.

## 14. Form Request klase

Predloženi fajlovi:

```text
app/Http/Requests/PasskeyLoginOptionsRequest.php
app/Http/Requests/PasskeyLoginRequest.php
app/Http/Requests/PasskeyRegistrationOptionsRequest.php
app/Http/Requests/StorePasskeyRequest.php
app/Http/Requests/DeletePasskeyRequest.php
```

Ne koristiti paketove `PasskeyRegistrationRequest` i `PasskeyVerificationRequest`, jer njihove options metode pozivaju `$this->session()->pull(...)`.

Naše Request klase treba da rade samo:

- validaciju strukture;
- parsiranje credentiala preko `WebAuthn::fromJson`;
- pretvaranje parse grešaka u `422` response.

Ceremony storage i autorizacija ostaju u servisima i kontrolerima.

## 15. Sanctum token politika

Minimalno kompatibilno izdavanje:

```php
$user->createToken($device->device_identifier)->plainTextToken;
```

Preporučena poboljšanja:

1. Koristiti čitljivo ime uređaja za naziv tokena.
2. Pre izdavanja obrisati prethodni token istog uređaja.
3. Postaviti rok važenja tokena.
4. Kasnije dodati Sanctum abilities ako postoje nivoi pristupa.

Ako se politika menja, izdvojiti zajednički `app/Services/DeviceTokenIssuer.php` koji koriste PIN i passkey login.

## 16. Obrada grešaka

| Situacija | HTTP status |
|---|---:|
| Neispravan request | 422 |
| Neispravan PIN/passkey | 401 ili 422 |
| Nema bearer tokena | 401 |
| Blokiran/neaktivan korisnik | 403 |
| Tuđi passkey ID | 404 |
| Istekla/potrošena ceremonija | 422 |
| Previše pokušaja | 429 |
| Uspešna registracija | 201 |
| Uspešno brisanje | 204 |

Za javni login ne razlikovati poruke za nepoznat uređaj, nalog bez passkeya, blokiran nalog i nepoznat credential. Detaljan razlog može biti u internom logu.

## 17. Testovi

Napraviti:

```text
tests/Unit/PasskeyCeremonyStoreTest.php
tests/Feature/PasskeyAuthenticationTest.php
tests/Feature/PasskeyManagementTest.php
```

### Ceremony store

- jedinstven ID;
- uspešna jednokratna potrošnja;
- replay je odbijen;
- istekao challenge je odbijen;
- login i registration tipovi se ne mogu zameniti;
- samo jedan paralelni zahtev može potrošiti ceremoniju;
- payload radi uz `serializable_classes=false`.

### Login

- nepoznat ili nepouzdan uređaj je odbijen;
- blokiran korisnik je odbijen;
- korisnik bez passkeya je odbijen;
- options sadrže challenge, RP ID i obaveznu user verification;
- `allowCredentials` pripada očekivanom korisniku;
- validan assertion izdaje Sanctum token;
- token pristupa zaštićenoj bankarskoj ruti;
- ne kreira se `web` sesija;
- credential drugog korisnika je odbijen;
- replay ceremony ID-a je odbijen;
- promena statusa ili device trusta između dva koraka je primećena;
- `last_used_at` i `last_login_at` se ažuriraju.

### Management

- sve rute zahtevaju Sanctum token;
- registration options zahtevaju confirmation grant;
- grant drugog korisnika ili drugog tokena je odbijen;
- grant i registraciona ceremonija su jednokratni;
- passkey se vezuje za autentifikovanog korisnika;
- credential JSON se ne vraća;
- tuđi passkey se ne može obrisati;
- brisanje zahteva novu PIN potvrdu;
- poslednji passkey može biti obrisan.

Kontrolerski testovi mogu mockovati paketove akcije, ali najmanje jedan integration test treba da koristi stvaran WebAuthn fixture kako bi dokazao kompatibilnost JSON formata sa paketom.

## 18. Redosled implementacije

1. [Završeno] Dodati active-user middleware, rate limitere i PIN lockout.
2. Obezbediti aktivaciju i prvi PIN enrollment tokenom.
3. Pozvati `Passkeys::ignoreRoutes()` i proveriti route listu.
4. Eksplicitno podesiti RP ID, origins, user handle secret i TTL.
5. Dodati `PasskeyCeremonyStore`.
6. Dodati unit testove za TTL, replay i binding.
7. Proširiti PIN confirmation da izdaje management grant.
8. Dodati passkey login options.
9. Dodati passkey login verification i Sanctum token issuance.
10. Dodati registration options.
11. Dodati završetak registracije.
12. Dodati listanje i brisanje passkeyeva.
13. Dodati feature i security testove.
14. Pokrenuti route proveru, Pint, testove i dependency audit.
15. Tek zatim povezati Expo klijent.

## 19. Završna verifikacija

```bash
php artisan route:list --path=passkeys -v
vendor/bin/pint --test
composer test
composer audit --locked
```

Kriterijumi prihvatanja:

- Nema paketovih root-level `web` ruta.
- Management rute koriste `auth:sanctum`.
- Login options i verification ne pokreću Laravel session.
- Uspešan passkey login vraća Sanctum bearer token.
- Challenge je kratkotrajan, server-side i jednokratan.
- Login je vezan za pouzdan uređaj.
- Credential mora pripadati korisniku tog uređaja.
- Blokiran korisnik ne može dobiti niti koristiti token.
- Dodavanje i brisanje passkeya zahtevaju nedavnu PIN potvrdu.
- Nijedan fajl u `vendor/` nije izmenjen.

## Napomena za budući Expo korak

Backend će očekivati standardni WebAuthn JSON kompatibilan sa `web-auth/webauthn-lib`. Nativni iOS/Android passkey modul mora vratiti taj format.

`expo-local-authentication` nije passkey implementacija: on samo lokalno prikazuje biometrijski prompt i ne proizvodi WebAuthn potpis koji backend može proveriti. Nativna integracija će zahtevati odgovarajući passkey/Credential Manager modul i pravilno podešene iOS associated domains i Android Digital Asset Links.
