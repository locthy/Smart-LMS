FROM php:8.3-apache

# Install system dependencies required for Moodle extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    unzip \
    git \
    dos2unix \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions required by Moodle
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pgsql \
        pdo_pgsql \
        gd \
        zip \
        intl \
        soap \
        opcache \
        bcmath \
        exif

# Enable Apache rewrite module
RUN a2enmod rewrite

# Configure Apache DocumentRoot to point to moodle/public (Moodle 5.x standard)
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Configure PHP settings required for Moodle
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/moodle.ini \
    && echo "max_input_vars = 5000" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "upload_max_filesize = 100M" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "post_max_size = 100M" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "date.timezone = Asia/Ho_Chi_Minh" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "opcache.enable = 1" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "opcache.memory_consumption = 128" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "opcache.max_accelerated_files = 10000" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "opcache.revalidate_freq = 60" >> /usr/local/etc/php/conf.d/moodle.ini

# Copy entrypoint script
COPY docker/moodle/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN dos2unix /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
