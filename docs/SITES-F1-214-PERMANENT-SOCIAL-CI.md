# F1-214 — Testes sociais obrigatórios na suíte principal do Sites

As F1-209 a F1-213 criaram o fluxo de metadados sociais, cartão textual, leitura privada e paridade exata entre o revisor e o pacote exportado. Cada entrega tinha uma suíte específica de pull request, mas uma alteração posterior no motor poderia evitar esses testes caso não tocasse os arquivos de seus respectivos workflows.

A F1-214 integra permanentemente ao workflow principal `synthetic-php.yml` os testes `f210-social-card-inspection.php`, `f211-private-social-card-readback.php` e `f213-visual-review-export-parity.php`, após o teste F1-209 já presente. Todos passam a executar em PHP 8.3 e 8.4 em cada PR e em push na `main`.

O script `ci/f214_social_regression_contract.py` impede que os quatro comandos sejam acidentalmente omitidos, duplicados ou embaralhados, além de proteger os gatilhos PR/main, as permissões somente leitura, a matriz de PHP e a própria rotina de verificação. Testa 14 mutações negativas **somente em memória**, sem rede ou alteração dos arquivos reais.

A suíte Laravel F1-03 já descobre automaticamente os testes de integração e continua exercitando o ambiente com e sem ativação das rotas sintéticas. Os jobs específicos das entregas anteriores continuam disponíveis como evidência, mas não são a única proteção contra regressões futuras.

Nenhuma mudança oferece publicação, CRM, autenticação do cliente, imagem remota, URL social real, deploy, gastos ou alteração na `main`. OP01/OP02/OP04 e os dez gates operacionais permanecem bloqueados.
