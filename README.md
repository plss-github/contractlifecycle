# Ciclo de vida de contratos (contractlifecycle)

Plugin GLPI (11.0.x – 12.0.x) da Sercompe para o ciclo de vida dos contratos de serviço: tipos de contrato
configuráveis, vínculo automático do chamado ao contrato, extrato de horas com cobrança mínima por faixa de
horário, saldos, Per-Event com aprovação e avisos de renovação.

## Conceitos

- **Configuração em 3 níveis**: geral → tipo de contrato → contrato. Cada campo vazio herda do nível acima
  ("Personalizar" liga a sobrescrita). Os tipos são combinações de comportamentos, não regras fixas:
  franquia limitada/ilimitada, renovação automática, cobrança em valor, aprovação, categorias elegíveis.
  A instalação cria os tipos **Gestão**, **Banco de Horas** e **Per-Event**.
- **Serviços do contrato**: categorias ITIL cobertas (aba *Serviços contratados*). O chamado é vinculado pelo
  ID exato da categoria, ou pela raiz da categoria quando o tipo define "cobre toda categoria cuja raiz se chama"
  (ex.: Per-Event → `IT Support`). Vários contratos: estratégia global (padrão: ilimitado → limitado → o que vence
  primeiro). O vínculo usa a tabela do core (aba *Contratos* do chamado).
  O "contrato padrão" da entidade (core) é ignorado na abertura do chamado — configurável em *Configuração
  geral → Chamados*; contrato escolhido no formulário ou por regra de negócio continua valendo. Vínculo repetido
  (ex.: regra que atribui um contrato já vinculado) é descartado em vez de derrubar a gravação do chamado.
- **Extrato**: toda entrada (criação, recarga, ajuste, renovação) e saída (apontamento) de horas. Nada é apagado:
  tarefa alterada/excluída gera estorno + novo débito. Saldo pode ficar negativo; recarga abate o negativo.
- **Arredondamento** (por faixa, com o tempo da faixa como bloco/mínimo): *sempre para cima* (padrão; bloco de
  15 min: 0h12 → 0h15, 1h13 → 1h15, 0h16 → 0h30), *só cobrança mínima* (debitado = max(tempo real, mínimo)) ou
  *sem arredondamento* (tempo real). Faixas: horário comercial, fora do horário,
  fim de semana/feriado (feriados do calendário do GLPI). Apontamento que cruza faixas: regra de prevalência
  (início | fim | comercial | não comercial) e, com *cobrar pela faixa mais cara*, a faixa de maior cobrança (empate segue a regra).
  O tempo debitado vai também para *Custos* do chamado. "Cobrar pela faixa mais cara" (antes "arredondar") só escolhe
  a faixa de um apontamento que cruza faixas; o arredondamento do tempo é a opção acima.
- **Per-Event**: chamado numa categoria elegível sem contrato abre aprovação para os pontos focais da entidade
  (ou um grupo de reserva). Aprovado → contrato Per-Event com vigência base e a versão vigente da *Tabela
  Per-Event* (valor/hora e multiplicadores; nova versão só afeta contratos futuros). Enquanto não aprova, o
  apontamento pode ficar bloqueado.
- **Pontos focais** (aba na entidade): contrato, financeiro e técnico, vários usuários por papel; sem ninguém,
  vale a entidade pai.
- **Ação automática diária** (`lifecycle`): avisos de renovação (ex.: 90/60/30 dias) e renovação automática
  (na franquia limitada pode recarregar a franquia).
- **Troca de categoria/entidade**: sem horas lançadas, o vínculo é refeito; com horas, aparece
  *Re-alocar horas* abaixo da barra do chamado.

- **Automação de formulários** (aba *Ciclo de vida* no formulário nativo do GLPI): ao enviar o formulário, logo
  que o chamado é criado, executa a ação escolhida:
  - **Onboard**: entidade do cliente (sob a entidade pai configurada; reaproveita se já existir), cópia para ela
    dos ramos marcados do catálogo modelo ("Catálogo Geral"), um contrato por tipo (área → tipo na configuração
    geral; padrão Gestão) com esses serviços e as propostas anexadas, usuários sem senha (Master/Técnico/Financeiro)
    com perfil na entidade e como pontos focais, e e-mails de acompanhamento como observadores do chamado.
    Os contratos ficam **aguardando ativação** e começam a valer no fechamento do chamado (com a duração planejada).
  - **Recarga**: lança as horas no extrato. **Renovação**: estende a vigência (e recarrega a franquia, se for o
    caso). **Não renovação**: desliga a renovação automática. **Cancelamento**: encerra na data informada ou,
    sem ela, N dias após o pedido (configurável, padrão 30).
  - Resistente a mudanças no formulário: cada campo guarda o UUID e o nome da pergunta; se o UUID sumir, a
    pergunta é reencontrada pelo nome. A aba mostra o estado de cada campo e tem *Mapear automaticamente*.
    Nada interrompe o envio: o relatório de cada execução fica na sub-aba *Execuções* e num acompanhamento
    privado do chamado.

## Telas

| Onde | O quê |
|---|---|
| Contrato (topo) | Barra de uso (consumo da franquia ou tempo de vigência) e data final |
| Contrato → formulário | Data final, franquia contratada, versão da tabela Per-Event |
| Contrato → *Regras do contrato* | Sobrescritas do contrato (sub-abas) |
| Contrato → *Serviços contratados* | Categorias cobertas |
| Contrato → *Extrato de horas* | Lançamentos, resumo (faixa/técnico/mês), recarga e ajuste; botão *Calendário* (lançamentos por dia) |
| Tipo de contrato → *Regras do contrato* | Comportamento do tipo |
| Chamado (painel lateral, topo da seção principal) | Barra do contrato, horas do chamado, aprovação pendente, *Re-alocar horas* |
| Entidade → *Pontos focais* | Usuários por papel |
| Entidade → *Contratos (ciclo de vida)* | Contratos da entidade e recursivos herdados, com uso e saldo |
| Gerência → *Ciclo de contratos* | Saldos, Tabelas Per-Event, Configuração geral |
| Formulário → *Ciclo de vida* | Ação do formulário, mapeamento das perguntas, seções de serviço → área, execuções |
| Perfil → *Ciclo de contratos* | Direitos: configuração; extrato (ler, recarga/ajuste, re-alocar) |

## Testes (laboratório)

No laboratório (`docker compose`, ver o repositório do lab):

```
make test   # tests/lab/run.php (regras e cenários, entidade LABTEST) e tests/lab/forms.php (envios reais dos
            # formulários do QA: Onboard, Recarga, Renovação, Não renovação/Cancelamento; desfaz tudo no fim)
            # tests/lab/links.php: vínculo chamado ↔ contrato e serviços (roda também num GLPI vazio)
make demo   # tests/lab/demo.php: dados LABDEMO na Pamplona para ver as telas
```

## Coexistência e migração

O QA vincula contratos hoje por regras de negócio "CONTRATO - Atribuição do contrato …" (categoria → contrato)
e lista serviços no campo do Fields "ICs > Serviços". O plugin ignora os vínculos criados por essas regras (não
os trata como escolha manual) e mantém o próprio encaixe; a migração desses dados para os serviços do plugin é
uma etapa à parte.
