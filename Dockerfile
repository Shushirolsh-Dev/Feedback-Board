FROM php:8.3-cli-alpine
RUN apk add --no-cache su-exec && docker-php-ext-install pdo_sqlite
WORKDIR /app
COPY . .
RUN mkdir -p /app/storage /var/data && chown -R www-data:www-data /app/storage /var/data && chmod +x /app/docker-entrypoint.sh
ENV FEEDBACK_DB_PATH=/var/data/feedback.sqlite
EXPOSE 8080
ENTRYPOINT ["/app/docker-entrypoint.sh"]
