# Imagine Sites Engine — Fundação Laravel 13 isolada (F1-03)

**Não operacional.** Nenhum login, banco real, migrations executadas, filas reais,
integração Portal/CRM/Cloudflare, AI Agents, publicação ou dados de clientes.

O bootstrap técnico foi adaptado da fundação Laravel do Portal
(`rafhael100/imagine-portal`, branch `dev/fast-track-portal-2026-11-05`).
O aplicativo Sites possui seu próprio composer.json, lockfile, pastas, configuração
e placeholders para banco PostgreSQL exclusivo; **não** reaproveita banco/usuários/sessões.

## Validação sintética
```sh
cd apps/sites
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress --no-scripts
php artisan test --no-ansi
php artisan route:list
```
A rota `/up` prova apenas bootstrap Laravel, **não** saúde do SQL, readiness ou
capacidade operacional. Demais rotas (login, api, checkout, leads, publisher)
devem continuar 404. Não executar migrate ou serve na VPS, nem usar dados reais.

As regras puras de F1-01 e F1-02 seguem na raiz e **não estão conectadas**
ao Laravel. Autenticação forte, RLS, persistência, filas, autorização oficial,
assinaturas e integração são etapas posteriores sob gates.
