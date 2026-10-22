# The Swoole image of the runtime tests: phpswoole/swoole plus ext-pcntl, so the Swoole guard test has a signal handler to guard.
ARG BASE=phpswoole/swoole:6.2.3-php8.3
FROM ${BASE}
RUN docker-php-ext-install pcntl
