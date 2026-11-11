# F1-201 — Registro verificável da revisão diretamente na interface Laravel

O visualizador sintético de comparação de layouts F1-188 passa a calcular a evidência canônica definida na F1-200 e a apresentar seu SHA-256, o estado pendente da nova revisão e o JSON exato, em uma seção expansível somente de leitura.

A rota /_lab/sites/v1/layout-diff continua exclusiva do processo de testes Laravel CLI/testing/loopback, com as mesmas flags de laboratório. A classe SyntheticLayoutChangeReviewEvidenceV1 só aceita o plano de revisão validado e reconstrói os hashes do HTML original e do novo HTML; a rota nega resposta se qualquer indicador de publicação, aceite de cliente ou entrega CRM for verdadeiro. Não recebe HTML de cliente, credencial, token, CNPJ, dados pessoais, arquivo, formulário ou URL externa.

A seção de evidência mostra o digest do registro e os campos explícitos de não publicação, não entrega e ausência de aceite. O JSON é escapado pelo Blade, mantendo CSS/CSP e iframe sandbox do revisor. O teste PHP Laravel confere o SHA-256 de cada byte do JSON mostrado, cruza os hashes de HTML anterior/novo, verifica 18 versões de teste em desktop e mobile (36 respostas), valida que versões inválidas falham com 404 e que rotas antigas não herdam automaticamente a nova visualização.

Evidência não é assinatura, evento de aprovação nem prova de identidade real. A revisão, o Portal, o CRM e os dez gates operacionais seguem pendentes. O desenvolvimento permanece em PR draft, sem merge, deploy, DNS, VPS, Cloudflare, dados reais ou custo adicional.
