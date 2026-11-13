# F1-279 — Combinar backoff local e Retry-After do CRM sem perder T0

Este componente combina as regras anteriores F1-277 e F1-278 em uma data única para **reconsiderar** uma tentativa técnica, sem conceder permissão para executá-la.

- O prazo original de recebimento durável T0 continua fixo; com 72 h de janela inicial e 432 h de limite total, sem reinício.
- Aplica a data mais conservadora entre o backoff das tentativas anteriores e o intervalo `Retry-After` calculado sobre **o instante de observação da resposta do fornecedor**, não sobre o horário da consulta atual.
- Nenhuma proposta ocorre após o segundo exato T0+432h, mesmo que o fornecedor informe nova espera.
- HTTP 202, 200, 201, 204 e 409 não viram provas de entrega ou autorização para reenvio. Falhas de autenticação e respostas desconhecidas também não geram planos.
- Há rejeição explícita de observações futuras, anteriores a T0, histórico terminal, ausência de data elegível e fusos inconsistentes.
- Os resultados nunca permitem despachar, armazenar, confirmar, responder sucesso ao visitante ou alongar o prazo. Testes incluem sete erros transitórios e esperas 0–86400 segundos.

**Limite:** o relógio de resposta e o próprio T0 são entradas de teste; a classe não prova registros imutáveis, autenticação, identidade ou assinatura CRM. Antes de qualquer integração real são necessários outbox durável, ledger, nonce, autorização atual do Portal/CRM e auditoria. Dez gates operacionais permanecem `BLOCKED`.