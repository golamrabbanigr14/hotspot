FROM php:8.1-apache

# MySQL ড্রাইভার ইনস্টল করার জন্য এই কমান্ডটি যোগ করুন
RUN docker-php-ext-install mysqli pdo pdo_mysql

COPY . /var/www/html/
