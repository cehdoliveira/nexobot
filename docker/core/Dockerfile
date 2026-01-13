FROM php:8.3-apache
COPY site.conf /etc/apache2/sites-available/site.conf
RUN a2ensite site.conf
RUN apt-get update 
RUN apt-get install -y apt-utils
RUN apt-get install -y libzip-dev 
RUN apt-get install -y zlib1g-dev
RUN apt-get install -y git unzip

# Instalar librdkafka (dependência do rdkafka PHP)
RUN apt-get install -y librdkafka-dev

RUN docker-php-ext-install pdo pdo_mysql zip

# Instalar Redis extension
RUN pecl install redis-6.0.2 && docker-php-ext-enable redis

# Instalar RdKafka extension
RUN pecl install rdkafka-6.0.3 && docker-php-ext-enable rdkafka

RUN a2enmod headers rewrite

# Instalar Composer na pasta lib
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Criar diretório lib caso não exista
RUN mkdir -p /var/www/nexbot/app/inc/lib

# Definir permissões adequadas
RUN chown -R www-data:www-data /var/www/nexbot

# Copiar e configurar o script de entrypoint
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Definir o entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]