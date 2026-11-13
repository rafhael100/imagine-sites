# F1-189 — Auditoria Chromium da galeria rica após link de pular a prévia

No PR F1-188, a execução completa encontrou um teste antigo da galeria rica F1-163 que ainda exigia apenas oito links e falhava na primeira página, embora o link de acessibilidade adicionado em F1-183 seja uma mudança intencional.

A correção preserva os nove links esperados, sendo exatamente os dois últimos os fragmentos locais #apos-previa e #detalhes. Confere a existência de a.skip-preview apontando para o alvo pós-iframe com tabindex=-1 e mantém os sete links antigos de produto, estilo e dispositivo em seus caminhos internos fixos. Qualquer destino desconhecido ou URL externa continua sendo recusado.

Não foram removidas checagens de overflow, contraste, iframe sandbox, CSP/noindex, conteúdo inerte e digests SHA. A galeria permanece privada e fictícia, sem sessão de cliente, autorização real, CRM, deploy ou novos custos.

Falha observada: execução [43027772642](https://github.com/rafhael100/imagine-sites/actions/runs/43027772642), AssertionError em len(result["links"])==8. O objetivo deste PR é garantir que mudanças de acessibilidade se reflitam nos testes de todo o produto, não apenas nas suítes unitárias.
