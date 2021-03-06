FROM php:7.1

ENV COMPOSER_ALLOW_SUPERUSER 1

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    && rm -r /var/lib/apt/lists/* \
    && cd /root/ \
    && curl -sS https://getcomposer.org/installer | php \
    && ln -s /root/composer.phar /usr/local/bin/composer

WORKDIR /code

# Initialize
COPY . /code/
COPY php.ini /usr/local/etc/php/

RUN composer install --no-interaction

CMD php /code/run.php --data=/data
