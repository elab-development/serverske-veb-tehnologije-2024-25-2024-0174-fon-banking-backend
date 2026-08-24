CREATE TABLE accounts (
    id varchar(255) NOT NULL,
    user_id integer(11) NOT NULL,
    title varchar(255) NOT NULL,
    name varchar(255) NOT NULL,
    account_number varchar(255) NOT NULL,
    iban varchar(255),
    color varchar(255) NOT NULL,
    currency varchar(255) NOT NULL,
    created_at datetime,
    updated_at datetime,
    PRIMARY KEY(id)
)
CREATE TABLE activation_codes (
    id integer(11) NOT NULL AUTO_INCREMENT,
    user_id integer(11) NOT NULL,
    code varchar(255) NOT NULL,
    expires_at datetime NOT NULL,
    used_at datetime,
    created_at datetime,
    updated_at datetime,
    PRIMARY KEY(id)
)
CREATE TABLE cards (
    id varchar(255) NOT NULL,
    account_id varchar(255) NOT NULL,
    card_id varchar(255) NOT NULL,
    card_type varchar(255) NOT NULL,
    expire_date varchar(255) NOT NULL,
    owner_name varchar(255) NOT NULL,
    currency varchar(255) NOT NULL,
    cvv varchar(255) NOT NULL,
    created_at datetime,
    updated_at datetime,
    PRIMARY KEY(id)
)
CREATE TABLE devices (
    id integer(11) NOT NULL AUTO_INCREMENT,
    user_id integer(11) NOT NULL,
    device_identifier varchar(255) NOT NULL,
    device_name varchar(255),
    is_trusted tinyint(1) NOT NULL,
    last_login_at datetime,
    created_at datetime,
    updated_at datetime,
    PRIMARY KEY(id)
)
CREATE TABLE payment_templates (
    id integer(11) NOT NULL AUTO_INCREMENT,
    user_id integer(11) NOT NULL,
    title varchar(255) NOT NULL,
    receiver_name varchar(255) NOT NULL,
    receiver_account_number varchar(255) NOT NULL,
    payment_code varchar(255),
    reference_number varchar(255),
    created_at datetime,
    updated_at datetime,
    PRIMARY KEY(id)
)
CREATE TABLE pin_enrollment_tokens (
    id integer(11) NOT NULL AUTO_INCREMENT,
    user_id integer(11) NOT NULL,
    device_id integer(11) NOT NULL,
    token_hash varchar(255) NOT NULL,
    purpose varchar(255) NOT NULL,
    expires_at datetime NOT NULL,
    consumed_at datetime,
    created_at datetime,
    updated_at datetime,
    PRIMARY KEY(id)
)
CREATE TABLE transactions (
    id varchar(255) NOT NULL,
    recipient_account_id varchar(255) NOT NULL,
    recipient_name varchar(255) NOT NULL,
    sender_account_id varchar(255) NOT NULL,
    model integer(11),
    reference_number varchar(255),
    amount numeric NOT NULL,
    currency varchar(255) NOT NULL,
    sender_amount numeric,
    sender_currency varchar(255),
    recipient_amount numeric,
    recipient_currency varchar(255),
    exchange_rate numeric,
    payment_purpose varchar(255),
    payment_code varchar(255),
    transaction_time datetime,
    status varchar(255) NOT NULL,
    card_number varchar(255),
    created_at datetime,
    updated_at datetime,
    PRIMARY KEY(id)
)
CREATE TABLE users (
    id integer(11) NOT NULL AUTO_INCREMENT,
    first_name varchar(255) NOT NULL,
    last_name varchar(255) NOT NULL,
    jmbg varchar(255) NOT NULL,
    phone_number varchar(255) NOT NULL,
    email varchar(255) NOT NULL,
    pin_hash varchar(255),
    status varchar(255) NOT NULL DEFAULT pending_activation,
    created_at datetime,
    updated_at datetime,
    PRIMARY KEY(id)
)
ALTER TABLE accounts ADD FOREIGN KEY (id) REFERENCES cards (account_id)
ALTER TABLE accounts ADD FOREIGN KEY (id) REFERENCES transactions (recipient_account_id)
ALTER TABLE accounts ADD FOREIGN KEY (user_id) REFERENCES users (id)
ALTER TABLE activation_codes ADD FOREIGN KEY (user_id) REFERENCES users (id)
ALTER TABLE cards ADD FOREIGN KEY (card_id) REFERENCES transactions (card_number)
ALTER TABLE devices ADD FOREIGN KEY (id) REFERENCES pin_enrollment_tokens (device_id)
ALTER TABLE devices ADD FOREIGN KEY (user_id) REFERENCES users (id)
ALTER TABLE payment_templates ADD FOREIGN KEY (user_id) REFERENCES users (id)
ALTER TABLE pin_enrollment_tokens ADD FOREIGN KEY (user_id) REFERENCES users (id)