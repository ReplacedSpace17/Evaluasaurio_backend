# Imagen base de PHP con Apache
FROM php:8.2-apache

# Habilitar módulos necesarios de PHP
RUN docker-php-ext-install pdo pdo_mysql

# Activar mod_rewrite (Slim necesita URLs amigables)
RUN a2enmod rewrite

# Copiar archivos del proyecto al contenedor
COPY . /var/www/html/

# Establecer el directorio de trabajo
WORKDIR /var/www/html

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Instalar dependencias de PHP
RUN composer install --no-dev --optimize-autoloader

# Exponer el puerto 80
EXPOSE 80

# Comando por defecto al iniciar el contenedor
CMD ["apache2-foreground"]
