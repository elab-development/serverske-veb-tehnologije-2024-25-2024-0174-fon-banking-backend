# FON Banking API

Serverska aplikacija za demonstracioni sistem mobilnog bankarstva FON Banking. API je razvijen u Laravelu i mobilnoj aplikaciji obezbedjuje aktivaciju uredjaja, prijavu PIN-om, pregled racuna i kartica, prenose novca, istoriju transakcija, kursnu listu i rad sa NBS IPS QR kodovima.

> Projekat je prototip namenjen demonstraciji. Ne povezuje se sa stvarnom bankom ili platnim sistemom i ne treba ga koristiti za obradu stvarnih finansijskih podataka.

Klijentska aplikacija: [fon-banking-frontend](https://github.com/Nenad005/fon-banking-frontend)

## Funkcionalnosti

- aktivacija uredjaja pomocu jednokratnog aktivacionog koda;
- postavljanje i provera cetvorocifrenog PIN-a;
- Bearer autentifikacija pomocu Laravel Sanctum tokena;
- pregled profila, racuna, izracunatih stanja i kartica;
- lokalni transferi izmedju racuna u demonstracionoj bazi;
- paginirana istorija transakcija sa pretragom i filterima;
- kursna lista i konverzija valuta;
- validacija i generisanje NBS IPS QR sadrzaja;
- OpenAPI 3.1 specifikacija i Swagger UI koji servira Laravel;
- SQLite baza i generator demonstracionih podataka.

API rute koriste prefiks `/api/v1`. Provera dostupnosti servera nalazi se na `/up`.

## REST API dokumentacija

Kompletna OpenAPI 3.1 specifikacija nalazi se u fajlu [`openapi.yaml`](openapi.yaml). Dokument obuhvata sve API funkcije, parametre, HTTP zaglavlja, autentifikaciju, formate zahteva i odgovora, statuse gresaka i primere podataka.

Laravel pomocu paketa [L5-Swagger](https://github.com/DarkaOnLine/L5-Swagger) direktno servira postojecu specifikaciju i interaktivni Swagger UI. Nije potrebno pokretati poseban Swagger Docker kontejner niti otvarati spoljni servis.

| Sadrzaj | Lokalna adresa | Javna adresa |
| --- | --- | --- |
| Swagger UI | `http://localhost:8000/api/documentation` | [fon-banking.duckdns.org/api/documentation](https://fon-banking.duckdns.org/api/documentation) |
| OpenAPI YAML | `http://localhost:8000/docs` | [fon-banking.duckdns.org/docs](https://fon-banking.duckdns.org/docs) |

Dugme **Authorize** prihvata Sanctum token dobijen preko `/api/v1/set_pin` ili `/api/v1/login`. Unosi se samo vrednost tokena, bez prefiksa `Bearer`. Nakon autorizacije opcija **Try it out** moze direktno da salje zahteve javnom ili lokalnom API serveru izabranom u Swagger UI-ju.

Specifikacija se po potrebi može otvoriti i u [Swagger Editor-u](https://editor.swagger.io/) izborom opcije **File > Import file**.

## Dijagram baze podataka

Interaktivni dijagram baze podataka dostupan je na `/laravel-erd/fon-banking` kada je server pokrenut.

Podaci za dijagram nalaze se u generisanom fajlu `docs/erd/fon-banking.sql`. Nakon izmene modela ili migracija dijagram se osvezava komandom:

```bash
APP_ENV=local php artisan erd:generate \
  --directory=app/Models \
  --file=fon-banking.sql \
  --excludes=cache,cache_locks,jobs,job_batches,failed_jobs,personal_access_tokens,notifications
```

## Potrebni alati

Za lokalno pokretanje bez Dockera potrebni su:

- PHP 8.3 ili noviji;
- Composer 2;
- PHP ekstenzije koje zahteva Laravel, ukljucujuci PDO SQLite;
- Git.

Za kontejnersko pokretanje dovoljni su Docker Engine i Docker Compose v2.

QR i kursne funkcionalnosti zahtevaju pristup internetu zbog komunikacije sa NBS i Frankfurter servisima.

## Lokalno pokretanje

Klonirajte repozitorijum i udjite u njegov direktorijum:

```bash
git clone https://github.com/Nenad005/fon-banking-backend.git
cd fon-banking-backend
```

Instalirajte PHP zavisnosti i napravite lokalnu konfiguraciju:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Napravite SQLite datoteku, formirajte semu i unesite demonstracione podatke:

```bash
touch database/database.sqlite
php artisan migrate:fresh --seed
```

Pokrenite API tako da bude dostupan i mobilnim uredjajima u lokalnoj mrezi:

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

Server je zatim dostupan na:

- `http://localhost:8000/up` na razvojnom racunaru;
- `http://localhost:8000/api/v1` kao osnovni API URL;
- `http://localhost:8000/api/documentation` kao interaktivni Swagger UI;
- `http://localhost:8000/docs` kao OpenAPI YAML specifikacija;
- `http://<LAN_IP_RACUNARA>:8000/api/v1` sa fizickog telefona.

LAN adresu racunara mozete pronaci u mreznim podesavanjima operativnog sistema. Telefon i racunar moraju biti na istoj mrezi, a firewall mora dozvoliti dolazni saobracaj na portu 8000.

## Lokalni `.env`

Za uobicajeno lokalno pokretanje dovoljne su sledece vrednosti. Ostale vrednosti iz `.env.example` mogu ostati nepromenjene.

```env
APP_NAME="FON Banking"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=sqlite
SESSION_DRIVER=file
CACHE_STORE=database
QUEUE_CONNECTION=database

PIN_LOGIN_MAX_ATTEMPTS=5
PIN_LOGIN_DECAY_SECONDS=900
PIN_CONFIRMATION_TTL=300
PIN_ENROLLMENT_TTL=600
DEVICE_TOKEN_TTL_MINUTES=43200

NBS_QR_API_URL=https://nbs.rs/QRcode/api/qr/v1
FRANKFURTER_API_URL=https://api.frankfurter.dev/v2

SEED_PERSON_COUNT=30
SEED_BUSINESS_COUNT=24
SEED_TRANSACTIONS_PER_PERSON=20
SEED_PEER_TRANSACTIONS_PER_PERSON=6
```

`APP_KEY` se ne unosi rucno; generise ga komanda `php artisan key:generate`. Kada `DB_DATABASE` nije postavljen, aplikacija koristi `database/database.sqlite`.

Seeder uvek pravi dva naloga pogodna za demonstraciju:

| Korisnik | Aktivacioni kod |
| --- | --- |
| Luka Nenadovic | `LUKA-2026` |
| Marko Nenadovic | `MARKO-2026` |

Aktivacioni kod se moze iskoristiti samo jednom. Za ponavljanje kompletnog aktivacionog toka ponovo formirajte bazu komandom `php artisan migrate:fresh --seed`.

## Pokretanje pomocu Dockera

Docker image iz projekta sadrzi PHP-FPM i nginx i unutar kontejnera slusa na portu 8080. Za opste lokalno okruzenje `compose.yaml` treba da izlozi taj port direktno na racunaru i da koristi named volume za SQLite bazu, bez spoljne proxy mreze ili domena.

Primer samostalnog `compose.yaml` fajla:

```yaml
name: fon-banking

x-backend-environment: &backend-environment
  APP_NAME: FON Banking
  APP_ENV: production
  APP_DEBUG: "false"
  APP_URL: http://localhost:8000
  LOG_CHANNEL: stderr
  LOG_LEVEL: info
  DB_CONNECTION: sqlite
  DB_DATABASE: /data/database.sqlite
  SESSION_DRIVER: array
  CACHE_STORE: file
  QUEUE_CONNECTION: sync

services:
  backend-init:
    build:
      context: .
    user: root
    env_file: .env.docker
    environment: *backend-environment
    command:
      - sh
      - -eu
      - -c
      - |
        test -n "$${APP_KEY}"
        chown www-data:www-data /data
        touch /data/database.sqlite
        chown www-data:www-data /data/database.sqlite
        if [ ! -f /data/.initialized ]; then
          setpriv --reuid=www-data --regid=www-data --init-groups php artisan migrate --seed --force
          touch /data/.initialized
        else
          setpriv --reuid=www-data --regid=www-data --init-groups php artisan migrate --force
        fi
    volumes:
      - backend-data:/data
    restart: "no"

  backend:
    build:
      context: .
    env_file: .env.docker
    environment: *backend-environment
    depends_on:
      backend-init:
        condition: service_completed_successfully
    ports:
      - "8000:8080"
    volumes:
      - backend-data:/data
    restart: unless-stopped

volumes:
  backend-data:
```

Napravite `.env.docker` pored `compose.yaml` fajla:

```env
APP_KEY=base64:OVDE_UNETI_GENERISANI_KLJUC

PIN_LOGIN_MAX_ATTEMPTS=5
PIN_LOGIN_DECAY_SECONDS=900
PIN_CONFIRMATION_TTL=300
PIN_ENROLLMENT_TTL=600
DEVICE_TOKEN_TTL_MINUTES=43200

NBS_QR_API_URL=https://nbs.rs/QRcode/api/qr/v1
FRANKFURTER_API_URL=https://api.frankfurter.dev/v2

SEED_PERSON_COUNT=30
SEED_BUSINESS_COUNT=24
SEED_TRANSACTIONS_PER_PERSON=20
SEED_PEER_TRANSACTIONS_PER_PERSON=6
```

Generisite Laravel aplikacioni kljuc bez instaliranja PHP-a na racunar:

```bash
docker build -t fon-banking-backend .
docker run --rm fon-banking-backend php artisan key:generate --show
```

Dobijenu vrednost, zajedno sa prefiksom `base64:`, unesite kao `APP_KEY` u `.env.docker`, a zatim pokrenite aplikaciju:

```bash
docker compose up --build -d
docker compose ps
```

Provera dostupnosti:

```bash
curl http://localhost:8000/up
curl --head http://localhost:8000/api/documentation
curl --head http://localhost:8000/docs
```

Swagger UI je nakon pokretanja dostupan na `http://localhost:8000/api/documentation` kao deo istog backend kontejnera.

Prikaz logova i zaustavljanje:

```bash
docker compose logs -f backend
docker compose down
```

Komanda `docker compose down` ne brise SQLite podatke. Za potpuno cistu bazu i ponovno seedovanje uklonite i named volume:

```bash
docker compose down -v
docker compose up --build -d
```

## Povezivanje mobilne aplikacije

Vrednost `EXPO_PUBLIC_API_URL` u frontend aplikaciji mora pokazivati na ovaj server:

| Okruzenje klijenta | API URL |
| --- | --- |
| Fizicki Android ili iPhone | `http://<LAN_IP_RACUNARA>:8000/api/v1` |
| Android emulator | `http://10.0.2.2:8000/api/v1` |
| iOS Simulator | `http://127.0.0.1:8000/api/v1` |

Detaljna uputstva nalaze se u README fajlu klijentskog repozitorijuma.

## Provere kvaliteta

Pokretanje serverskih testova:

```bash
composer test
```

Provera formatiranja PHP koda:

```bash
vendor/bin/pint --test
```

## Korisne komande

```bash
# Prikaz svih API ruta
php artisan route:list --path=api

# Potpuno ponovno formiranje demonstracione baze
php artisan migrate:fresh --seed

# Brisanje konfiguracionog i aplikacionog cache-a
php artisan optimize:clear
```

## Licenca

Projekat je razvijen u obrazovne svrhe u okviru Fakulteta organizacionih nauka Univerziteta u Beogradu.
