# SITES-F1-26 — Rotação limitada de credenciais de laboratório (Portal→Sites)

**Data:** 09/11/2026. **Natureza:** teste puro, sintético e sem segredos reais. Este recorte responde parcialmente à preparação técnica de [SITES-OP01](https://github.com/rafhael100/imagine-sites/issues/33), **não fecha nenhum dos seus critérios de aceite**.

## Mudança

- `SyntheticRotationVerifier` recebe uma chave atual de fixture e, opcionalmente, chave anterior com instante de corte e fim de carência.
- A chave anterior só pode validar envelope **emitido antes ou no corte**, com vencimento **no máximo até o fim da carência** e somente no intervalo de até 120 segundos configurado; não é permitida carência indefinida.
- O verificador calcula o MAC do envelope no formato v1 que já está acordado com o Portal, escolhe **uma única chave** e encaminha a operação ao `SyntheticSignedCommand::verify`, que valida campos/tempo e usa o mesmo `ReplayStore`. A verificação de credencial **não concede autorização comercial**.
- O modo de corte imediato sem chave anterior é obrigatório se houver suspeita de comprometimento. Um titular de chave anterior comprometida pode forjar timestamps dentro da janela: não usar carência em incidentes.
- Há negativas para chave inválida, assinatura adulterada, emissão posterior ao corte, validade além da carência, reuso de nonce inclusive entre chaves, expiração, falta de replay store e configuração insegura.

## Execução

`php tests/f26-rotation.php` — PHP 8.3+, somente memória. O workflow `synthetic-php.yml` roda a suíte em PR e push para `dev` em PHP 8.3/8.4, sem conexões externas além do download das ferramentas do runner.

## Limites e próximos gates

**Não** há chave `kid` negociada, mTLS, cofre de segredos, certificado, transporte HTTPS, PKI, verificação externa de identidade, rotação coordenada real, banco de replay compartilhado, revogação durável ou autenticação de contrato. O envelope v1 continua **candidato e sintético**. Teste de memória não comprova comportamento com falha de rede, múltiplos trabalhadores ou restauração de backups. A rotação com carência não evita abuso se a chave antiga estiver comprometida.

`main`, Cloudflare, Leaseweb, DNS, clientes e deploy permanecem intocados. GATE P0/RF-PEND/RV-CT, LGPD/AppSec e `config/operational-readiness.json` continuam bloqueados; `AGI-11-RB01` desabilitado.
