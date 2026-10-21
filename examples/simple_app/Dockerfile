# Build context is the repository root (see docker-compose.yml):
#   docker build -f examples/simple_app/Dockerfile .
FROM composer:2 AS build

WORKDIR /app

COPY examples/simple_app/composer.json ./
# Optional GitHub token (build secret) raises the GitHub API rate limit for
# the VCS repositories; builds also work without it when the limit allows.
RUN --mount=type=secret,id=github_token \
    if [ -s /run/secrets/github_token ]; then \
      composer config -g github-oauth.github.com "$(cat /run/secrets/github_token)"; \
    fi && \
    composer install --no-interaction --no-progress --prefer-dist --no-dev

FROM php:8.3.36-cli-alpine

WORKDIR /app

COPY --from=build /app/vendor ./vendor
COPY examples/simple_app/public ./public

EXPOSE 8080

# Served by the PHP built-in webserver (single process, demo only). The
# router script receives every request, so the guard screens all of them,
# including 404s. Production deployments should use FrankenPHP, FPM, or a
# long-running worker runtime instead.
CMD ["php", "-S", "0.0.0.0:8080", "public/index.php"]
