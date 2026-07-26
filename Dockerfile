FROM php:8.3-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    libonig-dev \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Configure GD with JPEG, PNG, WebP support
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp

# Install PHP extensions
# - pdo_pgsql: RadioChatBox's current data layer
# - pgsql: native PostgreSQL ext required by PramnosFramework's Database layer
# - mbstring: required by PramnosFramework core
RUN docker-php-ext-install pdo pdo_pgsql gd mbstring pgsql

# Configure PHP settings for file uploads and timezone
RUN echo "upload_max_filesize = 50M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 50M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "date.timezone = Europe/Athens" >> /usr/local/etc/php/conf.d/uploads.ini

# Install Redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis

# Install PCOV extension for code coverage (dev/testing only)
RUN pecl install pcov \
    && docker-php-ext-enable pcov

# Enable Apache modules
RUN a2enmod rewrite headers deflate expires

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files
COPY composer.json composer.lock* ./

# Install PHP dependencies (if any).
# NOTE: the PramnosFramework path repository (../PramnosFramework) lives outside
# the build context, so it is not resolvable at image-build time. In dev/test the
# project's vendor/ (with the framework symlink) is bind-mounted over this layer
# and ../PramnosFramework is mounted at /var/www/PramnosFramework (docker-compose.yml),
# so this build-time install is best-effort. Production framework packaging is a
# deferred decision — see docs/pramnos-migration/00-overview-and-bc-strategy.md.
RUN if [ -f composer.lock ]; then \
        composer install --no-dev --optimize-autoloader \
        || echo "composer install skipped at build time (framework path repo not in build context)"; \
    fi

# Copy application files
COPY . .

# Create upload directories and set permissions
RUN mkdir -p /var/www/html/public/uploads/photos \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80
