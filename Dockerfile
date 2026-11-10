FROM php:8.3-cli

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && docker-php-ext-install pdo_mysql

WORKDIR /app
COPY index.php db.php style.css script.js ./

# Runtime configuration. Override with: docker run -e PORT=3000 -p 3000:3000 ...
# Avoid 6666-6669: browsers block them (ERR_UNSAFE_PORT).
ENV PORT=1337 \
    APP_TIMEZONE=Asia/Manila
EXPOSE 1337

# Run as an unprivileged user.
USER www-data

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:' . getenv('PORT') . '/') === false ? 1 : 0);"

# Bind to 0.0.0.0 so the app is reachable from outside the container.
# "exec" makes PHP the main process, so `docker stop` shuts it down cleanly.
CMD ["sh", "-c", "exec php -S 0.0.0.0:${PORT} -t /app"]
