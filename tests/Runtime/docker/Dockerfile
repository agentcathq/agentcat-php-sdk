# The CLI image of the runtime tests: the official image plus ext-pcntl for the signal drain (D39).
FROM php:8.3.35-cli-bookworm
RUN docker-php-ext-install pcntl
