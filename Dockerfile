FROM php:7.2-cli-alpine

ENV COMPOSER_ALLOW_SUPERUSER 1
ENV TZ Asia/Tokyo

RUN apk add --no-cache --allow-untrusted \
    libxml2 \
  && apk add --no-cache --virtual=.build-deps --allow-untrusted \
    tzdata \
    pcre-dev \
    libxml2-dev \
    gcc \
    g++ \
    make \
    autoconf \
  && pecl install -o -f \
    xdebug \
    protobuf \
    grpc \
  && docker-php-ext-enable \
    opcache \
    xdebug \
    protobuf \
    grpc \
  && apk del .build-deps \
  && apk del *-dev \
  && rm -rf /tmp/pear \
  && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin/ --filename=composer \
  && mkdir -p /project/

WORKDIR /project
