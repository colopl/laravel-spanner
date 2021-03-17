FROM php:7.4-cli-alpine

ENV COMPOSER_ALLOW_SUPERUSER 1
ENV TZ Asia/Tokyo
ENV PATH="${PATH}:/project/vendor/bin"

# Fix for Alpine Linux 3.13 https://github.com/grpc/grpc/issues/25250
ENV CPPFLAGS="-Wno-maybe-uninitialized"

RUN apk add --no-cache bash gmp libxml2 libstdc++ \
  && apk add --no-cache --virtual=.build-deps autoconf curl-dev gcc gmp-dev g++ libxml2-dev linux-headers make pcre-dev tzdata \
  && docker-php-ext-install -j$(nproc) bcmath gmp \
  && pecl install -o -f protobuf grpc-1.35.0 \
  && docker-php-ext-enable grpc opcache protobuf \
  && apk del .build-deps \
  && rm -rf /tmp/* \
  && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin/ --filename=composer \
  && mkdir -p /project/

WORKDIR /project
