FROM dunglas/frankenphp:1-php8.4-alpine

# Install pgsql extension dan dependencies
RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql

# Install Redis extension
RUN apk add --no-cache autoconf gcc g++ make \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf gcc g++ make

WORKDIR /app

COPY . .

# Permissions
RUN chmod -R 775 storage bootstrap/cache

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
