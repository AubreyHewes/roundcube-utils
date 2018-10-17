FROM php:7-alpine

RUN \
  docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    mysqli \
  && \
  apk add curl && \
  mkdir /app && \
  cd /app && \
  curl -sSL https://getcomposer.org/installer | php && \
  apk del curl

COPY composer.json /app/composer.json
RUN cd /app && /app/composer.phar install --no-dev --no-plugins --no-scripts -o

COPY src /app/src

ENTRYPOINT ["php", "/app/src/application.php"]
