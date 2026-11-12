# F1-262 — Validação preventiva e sem persistência de contato

O Imagine Sites passa a dispor de uma classe de domínio isolada para avaliar, com exemplos fictícios, a integridade mínima de nome, e-mail/telefone e a presença de intenção de consentimento. O resultado nunca contém os valores fornecidos.

Regras principais:

- Recusa de campos excessivos, caracteres de controle, UTF-8 inválido, substituições invisíveis, alterações bidi e esquemas de URL nos dados de contato.
- Rejeição prioritária de sinal de honeypot, antes de processar os outros campos; não revela qual campo seria válido.
- Vínculo com o inspetor estrutural existente, sem alterar o contrato de paridade CRM F1-153.
- O consentimento assinalado é apenas **intenção não verificada**. Não é base legal comprovada, assinatura, captura autorizada ou autorização para enviar leads.
- Relatórios imutáveis contêm exclusivamente código de situação e três flags; nenhum nome, e-mail, telefone, IP ou token é armazenado.
- 20 casos negativos e positivos testados em PHP 8.3/8.4.

Ainda não existe formulário público, endpoint de envio, registro de consentimento, recepção CRM nem armazenamento pessoal. Os dez gates operacionais permanecem `BLOCKED`; a conexão real exige identificação, LGPD, política de retenção e confirmação independente.