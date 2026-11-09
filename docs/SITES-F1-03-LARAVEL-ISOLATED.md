# SITES-F1-03 — Fundação Laravel 13 independente

**Natureza:** implementação isolada sem funcionalidade operacional.
**Branch:** filha do F1-02, sem merge/deploy automático.

## Conteúdo
- Apps/sites: bootstrap Laravel da mesma linha da fundação do Portal, copiado
apenas como referência técnica; nenhum código de negócio, banco ou sessão compartilhado.
- PHP ^8.3 / Laravel ^13.0 com composer.lock retido como referência de versões
da fundação Portal. Validar lock com `composer validate --strict`, sem atualização implícita.
- PostgreSQL indicado só por nome/credenciais *placeholder*; não provisionado.
- Sem rotas de negócio. Somente health `/up`.
- PHPUnit: health e rejeição de rotas login, clientes, pagamentos e publicação.

## Evidência exigida
CI PHP 8.3/8.4 deve confirmar composer validate, composer install --no-scripts
e PHPUnit sem DB real, DNS, fornecedores ou credenciais.
Em caso de erro, corrigir especificamente em nova revisão e rerodar CI;
não declarar teste ou homologação sem prova.

## Não autorizado
Não usar Cloudflare/GitHub Actions para deploy, nem VPS, DNS ou dados reais.
Não executar migrations, SQL, captação, chave de PSP, CRM ou Agentes.

## Gates preservados
P0-DOC01–14, RF-PEND-01/RF-PEND-04, RV-CT, autenticação/tenancy,
dados pessoais e homologação permanecem abertos. AGI-11-RB01 desabilitado.
