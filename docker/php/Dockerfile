# Laravel 執行環境：PHP 8.3 + Apache（mod_php）
FROM php:8.3-apache

# PHP 擴充（pdo_mysql、intl、bcmath、zip、pcntl；mbstring 已內建）
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql intl bcmath zip pcntl

# pdftotext（中文 PDF 解析，Ch03）
RUN apt-get update \
 && apt-get install -y --no-install-recommends poppler-utils unzip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 讓 www-data 與 macOS 使用者同 UID/GID，容器寫入的檔案在主機上不會變成別人的
ARG UID=501
ARG GID=20
RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g ${GID} www-data

RUN a2enmod rewrite headers
COPY docker/php/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
