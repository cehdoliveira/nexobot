FROM php:8.4-apache

# Instalar dependências do sistema
RUN apt-get update && apt-get install -y --no-install-recommends \
    librdkafka-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    git \
    unzip \
    curl \
    cron \
    && rm -rf /var/lib/apt/lists/*

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar e instalar extensões PHP
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        mysqli \
        pdo \
        pdo_mysql \
        zip \
        gd

# Instalar Redis e Kafka via PECL (versões mais recentes compatíveis com PHP 8.4)
RUN pecl install redis rdkafka \
    && docker-php-ext-enable redis rdkafka

# Habilitar módulos do Apache
RUN a2enmod rewrite headers

# Copiar configurações
COPY site.conf /etc/apache2/sites-available/
COPY php.ini /usr/local/etc/php/
COPY crontab.txt /etc/cron.txt

# Configurar ServerName global para suprimir warning
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Habilitar sites e desabilitar padrão
RUN a2ensite site.conf \
    && a2dissite 000-default.conf

# Healthcheck
HEALTHCHECK --interval=30s --timeout=10s --retries=3 --start-period=40s \
    CMD curl -f http://localhost/ || exit 1

# Configurar timezone
ENV TZ=America/Sao_Paulo
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

WORKDIR /var/www

EXPOSE 80 8080

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
