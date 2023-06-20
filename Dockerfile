FROM php:8.1-cli-alpine

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER 1
ENV TZ Asia/Tokyo
ENV PATH="${PATH}:/project/vendor/bin"

# Fix for Alpine Linux 3.13 https://github.com/grpc/grpc/issues/25250
ENV CPPFLAGS="-Wno-maybe-uninitialized"

RUN pecl_mt_install() { \
        extension="${1}" \
        && extension_name="${extension%-*}" \
        && shift \
        && configure_option=${@:1} \
        && temporary="/tmp/pear/temp/${extension_name}" \
        && apk_delete="" \
        && if [ -n "${PHPIZE_DEPS}" ] && ! apk info --installed .phpize-deps > /dev/null; then \
            apk add --no-cache --virtual .phpize-deps ${PHPIZE_DEPS}; \
            apk_delete='.phpize-deps'; \
        fi \
        && if [ -n "${HTTP_PROXY:-}" ]; then \
            pear config-set http_proxy ${HTTP_PROXY}; \
        fi \
        && pecl install --onlyreqdeps --nobuild "${extension}" \
        && cd "${temporary}" \
          && phpize \
          && CFLAGS="${PHP_CFLAGS:-}" CPPFLAGS="${PHP_CPPFLAGS:-}" CXXFLAGS="${PHP_CXXFLAGS:-}" LDFLAGS="${PHP_LDFLAGS:-}" ./configure ${configure_option} \
          && make -j$(nproc) \
          && make install \
        && cd - \
        && rm -rf "${temporary}" \
        && if [ -n "${apk_delete}" ]; then apk del --purge --no-network ${apk_delete}; fi \
      } \
  && apk add --no-cache bash gmp libxml2 libstdc++ \
  && apk add --no-cache --virtual=.build-deps autoconf curl-dev gcc gmp-dev g++ libxml2-dev linux-headers make pcre-dev tzdata \
  && docker-php-ext-install -j$(nproc) bcmath gmp \
  && pecl_mt_install protobuf \
  && pecl_mt_install grpc \
  && docker-php-ext-enable grpc opcache protobuf \
  && apk del .build-deps \
  && rm -rf /tmp/* \
  && mkdir -p /project/

WORKDIR /project
