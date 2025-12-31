FROM php:8.3-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Configure GD with JPEG, PNG, WebP support
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql gd

# Configure PHP settings for file uploads
RUN echo "upload_max_filesize = 50M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 50M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini

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

# Install PHP dependencies (if any)
RUN if [ -f composer.lock ]; then composer install --no-dev --optimize-autoloader; fi

# Copy application files
COPY . .

# Create upload directories and set permissions
RUN mkdir -p /var/www/html/public/uploads/photos \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80
