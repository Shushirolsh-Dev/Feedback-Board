FROM php:8.3-cli-alpine
RUN docker-php-ext-install pdo_sqlite
WORKDIR /app
COPY . .
RUN mkdir -p /app/storage && chown -R www-data:www-data /app/storage
ENV FEEDBACK_DB_PATH=/app/storage/feedback.sqlite
EXPOSE 8080
USER www-data
CMD ["php","-S","0.0.0.0:8080","-t","public","public/router.php"]
