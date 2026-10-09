FROM node:24-bookworm-slim AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci --ignore-scripts
COPY resources/ resources/
COPY vite.config.js ./
RUN npm run build

FROM php:8.4-fpm AS runtime
RUN sed -i s,http://deb.debian.org,https://deb.debian.org,g /etc/apt/sources.list.d/debian.sources; \
    apt-get update && apt-get install -y --no-install-recommends nginx unzip git libpq-dev libsqlite3-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j4 pdo_pgsql pdo_mysql pdo_sqlite mbstring zip gd pcntl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
RUN mkdir -p storage/framework/cache/data storage/framework/views storage/framework/sessions storage/logs bootstrap/cache \
    && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && chmod -R a+rX /app \
    && chown -R www-data:www-data storage bootstrap/cache
COPY --from=assets /app/public/build public/build
COPY docker/nginx.conf /etc/nginx/tasksure.conf.template
COPY docker/php.ini /usr/local/etc/php/conf.d/tasksure.ini
RUN chmod +x docker/entrypoint.sh
EXPOSE 8080
ENTRYPOINT ["/app/docker/entrypoint.sh"]
