# Docker deployment

1. Copy the environment template and replace every placeholder:

   ```bash
   cp .env.example .env
   openssl rand -hex 32
   ```

2. For an existing site, keep the current Drupal `hash_salt` value in `.env`.
   Set `DRUPAL_TRUSTED_HOSTS` to comma-separated regex patterns for the domain,
   for example `^example\.com$,^www\.example\.com$`.

3. Build and start the containers:

   ```bash
   docker compose up -d --build
   docker compose ps
   docker compose logs -f app
   ```

   The site is available at `http://localhost:8080` by default.

4. To migrate the existing database, import a dump after the database becomes
   healthy:

   ```bash
   docker compose exec -T db sh -c \
     'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' \
     < mahlzeit.sql
   ```

   Copy existing public files into the named volume before testing media URLs:

   ```bash
   docker compose cp web/sites/default/files/. \
     app:/var/www/html/web/sites/default/files/
   ```

5. Rebuild Drupal caches after importing the database:

   ```bash
   docker compose exec app vendor/bin/dr cache:rebuild
   ```

The database and uploaded files live in named Docker volumes. Do not commit the
`.env` file or database dumps.
