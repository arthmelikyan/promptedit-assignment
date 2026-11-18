# PromptEdit Assignment

## Local setup

From the repository root, with Docker and Docker Compose installed:

```sh
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan db:seed --class=AdminUserSeeder --no-interaction
```

Open [http://localhost:8088](http://localhost:8088). The seeded local admin is `testingadmin@gmail.com` with password `testingadmin1234`. Set `ADMIN_PASSWORD` in `.env` before seeding to use a different password. Running the seeder again resets the seeded account's password.

To generate images, set `PIXELFORGE_API_KEY` in `.env`, then use **Buy $10** in the studio. The app can poll for results locally. To test webhooks, point the Pixelforge key at a public tunnel URL ending in `/webhooks/pixelforge` and set `PIXELFORGE_WEBHOOK_SECRET` to its signing secret.

## Tests

```sh
docker compose exec -T -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: app php artisan test
```
