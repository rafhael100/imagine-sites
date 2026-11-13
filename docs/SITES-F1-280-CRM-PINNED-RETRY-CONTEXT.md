# F1-280 — Vínculo CRM fixo em todas as tentativas de uma submissão

Uma nova tentativa de enviar uma submissão de lead não deve servir para alterar o CRM de destino, o formulário, a empresa ou a configuração do site de maneira silenciosa. A comparação de laboratório agora reforça esse vínculo antes de sequer considerar o horário de envio.

- A chave lógica continua sendo organização + submissão. Alterar qualquer uma gera recusa por escopo divergente.
- Com a chave igual, as referências de vínculo, site, formulário e CRM de destino precisam continuar exatamente iguais às da submissão original.
- O fingerprint sintético idempotente também precisa permanecer inalterado, e a janela F1-279 de T0/Retry-After deve estar elegível apenas temporalmente.
- HTTP 202 e 409, prazo de 432 horas expirado, período de espera incompleto e alterações entre empresas nunca resultam em aceitação.
- O retorno não permite mudar destino, renovar idempotência, armazenar ou enviar lead nem reconhecer visitante.

**Limite:** os UUIDs são alegações não autenticadas. Este componente isolado não comprova RLS, identidade Portal/CRM, histórico durável de submissão nem autorização operacional. Os dez gates continuam `BLOCKED`, sem acesso à rede ou dados reais.