FROM php:8.5-cli-alpine AS builder

RUN apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    linux-headers \
    curl-dev \
    openssl-dev

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
    sockets \
    openswoole

# Install Composer
RUN curl -fsSL https://getcomposer.org/installer -o composer-setup.php \
    && php composer-setup.php \
    && rm composer-setup.php \
    && mv composer.phar /usr/local/bin/composer

# Install dependencies
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# ------------------------------------
#
#            Final Stage
#
# ------------------------------------

FROM php:8.5-cli-alpine

RUN apk add --no-cache \
   libstdc++ \
   libpq \
   docker-cli

# Copy PHP extensions + config from builder
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d

# Copy vendor dependencies from builder — no Composer needed at runtime
COPY --from=builder /app/vendor /app/vendor

# Install Supercronic
ADD --chmod=0755 https://github.com/aptible/supercronic/releases/latest/download/supercronic-linux-amd64 /usr/local/bin/supercronic

# Copy application source
COPY bootstrap/ /app/bootstrap/
COPY src/       /app/src/

# Empty crontab file — must exist before Supercronic starts
RUN touch /etc/crontab

WORKDIR /app

# Run Supercronic (background) + PHP event listener (foreground)
CMD ["sh", "-c", "supercronic -quiet -inotify /etc/crontab & php src/main.php"]
