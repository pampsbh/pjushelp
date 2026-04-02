# PJUS Help-Desk — Contexto do Sistema

> **Stack:** PHP + MySQL + Vanilla JS (SPA) | Deploy: FTP → `poc.igorvilar.com.br/pjushelp`
> **Banco de dados:** `u665104129_poc` (MySQL, Hostinger)

---

## 1. Arquitetura de Arquivos

```
pjushelp/
├── index.html                      SPA completo (frontend)
├── context.md                      Este arquivo
├── api/
│   ├── config.php                  Conexão DB, helpers (respond, fail, me, admin, refreshSla, autoAssign)
│   ├── setup.php                   Cria tabelas + seed inicial (rodar 1x)
│   ├── migrate.php                 Sprint 2: attendant_profiles, assignment_rules, attendant_availability
│   ├── migrate2.php                Sprint 3: resolution_categories, reopen_settings, ticket_relations
│   ├── auth.php                    POST login / DELETE logout / GET sessão atual
│   ├── departments.php             GET departamentos; GET ?id=x&services=1
│   ├── tickets.php                 GET lista/detalhe | POST criar | PATCH (status/assign/resolve/reopen)
│   ├── ticket-relations.php        GET/POST/DELETE vínculos entre chamados
│   ├── tickets-search.php          GET busca de chamados (autocomplete, admin only)
│   ├── resolution-categories.php   GET categorias de resolução ativas
│   ├── upload.php                  POST anexo (imagem/PDF, máx 5 arquivos, 5 MB)
│   ├── my-queue.php                GET fila do atendente logado
│   ├── theme.php                   GET/POST configurações de aparência (white-label)
│   └── admin/
│       ├── metrics.php             GET métricas (main / monthly / by-dept / resolution-cats / quality)
│       ├── queue.php               GET fila admin com filtros
│       ├── attendants.php          GET workload | PATCH toggle disponibilidade
│       ├── reopen-settings.php     GET/PATCH prazo de reabertura
│       ├── priority-rules.php      CRUD regras de prioridade
│       └── services.php            CRUD serviços por departamento
└── uploads/                        Arquivos enviados pelos usuários
    └── .htaccess                   Bloqueia execução de PHP
```

---

## 2. Banco de Dados — Tabelas Implementadas

### `departments`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| name | VARCHAR(100) | |
| created_at | TIMESTAMP | |

**Seed:** `Facilities`, `Gente e Gestão`, `Tecnologia`

---

### `services`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| department_id | INT FK | → departments |
| name | VARCHAR(100) | |
| sla_hours | INT | Prazo de resolução em horas |
| created_at | TIMESTAMP | |

**Seed:**
| ID | Departamento | Serviço | SLA |
|---|---|---|---|
| 1 | Facilities | Manutenção predial | 48h |
| 2 | Facilities | Limpeza e conservação | 24h |
| 3 | Facilities | Controle de acesso | 8h |
| 4 | Gente e Gestão | Férias e afastamentos | 72h |
| 5 | Gente e Gestão | Benefícios | 48h |
| 6 | Gente e Gestão | Ponto e jornada | 24h |
| 7 | Tecnologia | Acesso e senha | 4h |
| 8 | Tecnologia | Equipamento | 24h |
| 9 | Tecnologia | Software e licença | 48h |
| 10 | Tecnologia | Rede e conectividade | 8h |

---

### `priority_rules`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| name | VARCHAR(100) | |
| description | TEXT | |
| condition_type | ENUM | `keyword`, `department`, `service`, `manual` |
| condition_value | TEXT | Valor a checar (texto, ID de dept/serviço) |
| is_active | TINYINT(1) | |
| created_at | TIMESTAMP | |

**Seed:**
- `keyword` → `urgente` (palavra no motivo do chamado)
- `service` → `7` (Acesso e senha sempre prioritário)
- `department` → `1` (Facilities — desativado por padrão)

---

### `users`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| name | VARCHAR(100) | |
| email | VARCHAR(150) UNIQUE | |
| password_hash | VARCHAR(255) | bcrypt via password_hash() |
| role | ENUM | `user`, `admin` |
| department_id | INT FK NULL | → departments |
| unit | VARCHAR(100) | Unidade/sede |
| phone_ext | VARCHAR(20) | Ramal |
| attendant_profile_id | INT FK NULL | → attendant_profiles (**a implementar**) |
| created_at | TIMESTAMP | |

**Seed:**
| E-mail | Senha | Role |
|---|---|---|
| admin@pjus.com.br | admin123 | admin |
| usuario@pjus.com.br | user123 | user |

---

### `tickets`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| user_id | INT FK | → users (quem abriu) |
| department_id | INT FK | → departments |
| service_id | INT FK | → services |
| description | TEXT | Mín. 20 caracteres |
| status | ENUM | `open`, `in_progress`, `resolved`, `closed` |
| is_priority | TINYINT(1) | Calculado automaticamente na abertura |
| priority_reason | VARCHAR(255) | Nome da regra que gerou a prioridade |
| sla_hours | INT | Snapshot do serviço no momento da abertura |
| sla_deadline | TIMESTAMP | `created_at + INTERVAL sla_hours HOUR` |
| sla_breached | TINYINT(1) | Atualizado a cada request por `refreshSla()` |
| resolved_at | TIMESTAMP NULL | Preenchido ao mudar status para resolved/closed |
| assigned_to | INT FK NULL | → users (atendente responsável) (**a implementar**) |
| assigned_at | TIMESTAMP NULL | (**a implementar**) |
| assignment_type | ENUM NULL | `auto`, `manual` (**a implementar**) |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | ON UPDATE CURRENT_TIMESTAMP |

---

### `ticket_attachments`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| ticket_id | INT FK | → tickets |
| file_name | VARCHAR(255) | Nome original do arquivo |
| file_path | VARCHAR(500) | Caminho público `/pjushelp/uploads/att_xxx.ext` |
| mime_type | VARCHAR(100) | Validado no backend |
| size_bytes | INT | |
| created_at | TIMESTAMP | |

---

### `ticket_history`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| ticket_id | INT FK | → tickets |
| changed_by | INT FK | → users |
| field_changed | VARCHAR(100) | `status`, `is_priority`, `observation`, `assigned_to` |
| old_value | TEXT | |
| new_value | TEXT | |
| created_at | TIMESTAMP | |

---

## 3. Tabelas a Implementar (próxima sprint)

### `attendant_profiles`
```sql
CREATE TABLE attendant_profiles (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  description TEXT,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
-- Seed: 'Gerente de T.I', 'Estagiário'
```

### `assignment_rules`
```sql
CREATE TABLE assignment_rules (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  service_id            INT NOT NULL,
  attendant_profile_id  INT NOT NULL,
  is_active             TINYINT(1) DEFAULT 1,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (service_id)           REFERENCES services(id),
  FOREIGN KEY (attendant_profile_id) REFERENCES attendant_profiles(id)
);
```

**Seed:**
| Serviço | Perfil |
|---|---|
| Acesso e senha (7) | Estagiário |
| Equipamento (8) | Gerente de T.I |
| Software e licença (9) | Estagiário |
| Rede e conectividade (10) | Estagiário |
| Manutenção predial (1) | Estagiário |
| Limpeza e conservação (2) | Estagiário |
| Controle de acesso (3) | Gerente de T.I |
| Férias e afastamentos (4) | Gerente de T.I |
| Benefícios (5) | Estagiário |
| Ponto e jornada (6) | Estagiário |

### `attendant_availability`
```sql
CREATE TABLE attendant_availability (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  user_id          INT NOT NULL UNIQUE,
  is_available     TINYINT(1) DEFAULT 1,
  max_open_tickets INT DEFAULT 10,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);
```

### Alterações em tabelas existentes
```sql
-- users: vínculo com perfil de atendente (NULL = usuário comum)
ALTER TABLE users
  ADD COLUMN attendant_profile_id INT NULL,
  ADD FOREIGN KEY (attendant_profile_id) REFERENCES attendant_profiles(id);

-- tickets: atribuição de responsável
ALTER TABLE tickets
  ADD COLUMN assigned_to      INT NULL,
  ADD COLUMN assigned_at      TIMESTAMP NULL,
  ADD COLUMN assignment_type  ENUM('auto','manual') NULL,
  ADD FOREIGN KEY (assigned_to) REFERENCES users(id);
```

---

## 4. Lógica de Atribuição Automática (a implementar em `POST /api/tickets`)

Executar imediatamente após salvar o ticket, nesta ordem:

```
1. Buscar assignment_rules WHERE service_id = ticket.service_id AND is_active = 1
   → Sem regra: fallback para atendente com menor carga (qualquer perfil)
   → Com regra: usar attendant_profile_id da regra

2. Buscar candidatos:
   users WHERE attendant_profile_id = perfil_da_regra
             AND role = 'admin'
             AND attendant_availability.is_available = 1
             AND (tickets abertos assigned_to = user.id) < max_open_tickets

3. Fallback se nenhum disponível no perfil:
   → Tentar perfil "Gerente de T.I" (pode receber qualquer chamado)
   → Se ainda sem disponível: assigned_to = NULL, sinalizar na fila admin

4. Desempate entre candidatos disponíveis:
   → Menor número de chamados abertos (status IN ('open','in_progress'))
   → Em caso de empate: assigned_at mais antigo (esperou mais tempo)

5. Gravar no ticket:
   assigned_to     = user.id
   assigned_at     = NOW()
   assignment_type = 'auto'

6. Gravar em ticket_history:
   field_changed = 'assigned_to'
   old_value     = NULL
   new_value     = user.name + ' (auto)'
```

---

## 5. Endpoints Implementados

| Método | Endpoint | Descrição |
|---|---|---|
| POST | `/api/auth.php` | Login |
| DELETE | `/api/auth.php` | Logout |
| GET | `/api/auth.php` | Sessão atual |
| GET | `/api/departments.php` | Lista departamentos |
| GET | `/api/departments.php?id=x&services=1` | Serviços do departamento |
| GET | `/api/tickets.php` | Lista chamados (paginado, filtros admin) |
| GET | `/api/tickets.php?id=x` | Detalhe com histórico e anexos |
| POST | `/api/tickets.php` | Abre chamado + prioridade automática |
| PATCH | `/api/tickets.php?id=x` | Altera status, prioridade, observação |
| POST | `/api/upload.php?ticket_id=x` | Upload de anexo |
| GET | `/api/admin/metrics.php` | Cards do dashboard |
| GET | `/api/admin/metrics.php?type=monthly` | Abertos x resolvidos (6 meses) |
| GET | `/api/admin/metrics.php?type=by-dept` | Distribuição por departamento |
| GET | `/api/admin/priority-rules.php` | Lista regras de prioridade |
| POST | `/api/admin/priority-rules.php` | Cria regra |
| PATCH | `/api/admin/priority-rules.php?id=x` | Edita/ativa/desativa regra |
| DELETE | `/api/admin/priority-rules.php?id=x` | Remove regra |
| GET | `/api/admin/services.php` | Lista todos os serviços |
| POST | `/api/admin/services.php` | Cria serviço |
| PATCH | `/api/admin/services.php?id=x` | Edita serviço |

---

## 6. Endpoints a Implementar (próxima sprint)

| Método | Endpoint | Descrição |
|---|---|---|
| PATCH | `/api/tickets.php?id=x&action=assign` | Atribuição manual pelo admin |
| PATCH | `/api/tickets.php?id=x&action=reassign` | Reatribuição com motivo obrigatório |
| GET | `/api/my-queue.php` | Fila do atendente logado |
| GET | `/api/admin/queue.php` | Fila geral com filtros |
| GET | `/api/admin/workload.php` | Carga por atendente |
| PATCH | `/api/admin/attendants.php?id=x` | Altera disponibilidade do atendente |

---

## 7. Frontend — Views Implementadas

### Visão Usuário
- **Abrir Chamado** — cards de departamento → dropdown de serviço (carregado via API) → textarea → upload → modal de confirmação com #ticket e SLA
- **Meus Chamados** — tabela paginada com status badge, prioridade, SLA (vermelho se vencido), link para detalhes

### Visão Admin
- **Dashboard** — 4 metric cards (abertos, prioritários, SLA vencido, taxa de resolução) + gráfico de barras mensal + gráfico donut por departamento
- **Fila de Chamados** — tabela com filtros (status, departamento, prioritário, SLA vencido), destaque visual nas linhas críticas, modal de gerenciamento (alterar status, marcar prioritário, observação → grava ticket_history)
- **Regras de Prioridade** — CRUD completo com toggle ativo/inativo
- **Serviços** — CRUD completo vinculado a departamento

### Views a Implementar (próxima sprint)
- **`/minha-fila`** — Fila do atendente: chamados atribuídos a ele, botões "Iniciar atendimento" e "Resolver", badge SLA tricolor (verde/amarelo/vermelho)
- **`/admin/equipe`** — Painel de carga: cards por atendente com status, contadores, toggle disponível/indisponível
- Coluna "Responsável" e filtro "Atendente" na fila admin
- Campo "Reatribuir" no modal de detalhe do chamado

---

## 8. Regras de Negócio Vigentes

- `sla_hours` é snapshot — nunca recalculado após abertura do chamado
- `sla_breached` atualizado a cada request via `refreshSla()`
- `ticket_history` gravado a cada mudança de status ou atribuição
- Prioridade avaliada na abertura contra todas as `priority_rules` ativas
- Chamado prioritário **ou** com SLA vencido → linha destacada (fundo avermelhado) nas tabelas
- Uploads: máx. 5 arquivos por chamado, 5 MB total, só imagem ou PDF
- Senhas: bcrypt via `password_hash()` / `password_verify()`
- Autenticação: sessão PHP (`session_name('HELPDESK')`)

---

## 9. Sprint 3 — Ciclo de Vida Pós-Fechamento (implementado)

### Novas tabelas

| Tabela | Descrição |
|---|---|
| `resolution_categories` | 4 slugs: `resolved`, `not_reproducible`, `duplicate`, `cancelled_by_user` |
| `reopen_settings` | Prazo máximo em dias para reabertura (padrão: 7 dias) |
| `ticket_relations` | Vínculos bidirecionais entre chamados (`related` ou `duplicate`) |

### Novas colunas em `tickets`

| Coluna | Tipo | Descrição |
|---|---|---|
| `resolution_category_id` | INT FK | Obrigatório ao fechar |
| `resolution_notes` | TEXT | Obrigatório para `not_reproducible` e `duplicate` |
| `duplicate_of` | INT FK → tickets | Atalho para o chamado original |
| `reopen_count` | INT | Quantas vezes foi reaberto |
| `last_closed_at` | TIMESTAMP | Base para calcular prazo de reabertura |
| `reopen_deadline` | TIMESTAMP | `last_closed_at + max_days_to_reopen` |

### Endpoints novos

| Método | Endpoint | Quem | Descrição |
|---|---|---|---|
| PATCH | `/tickets.php?id=X&action=resolve` | admin/atendente | Fecha com categoria; cria relação se duplicado; fecha duplicados em lote |
| PATCH | `/tickets.php?id=X&action=reopen` | dono do chamado ou admin | Reabre; valida prazo e slug != duplicate para usuário comum |
| GET | `/ticket-relations.php?id=X` | admin | Lista vínculos |
| POST | `/ticket-relations.php?id=X` | admin | Cria vínculo bidirecional |
| DELETE | `/ticket-relations.php?id=X&relatedId=Y` | admin | Remove vínculo bidirecional |
| GET | `/tickets-search.php?q=&excludeId=` | admin | Autocomplete para vincular chamados |
| GET | `/resolution-categories.php` | logado | Lista categorias ativas |
| GET | `/admin/reopen-settings.php` | fullAdmin | Lê configuração de prazo |
| PATCH | `/admin/reopen-settings.php` | fullAdmin | Atualiza prazo (body: `{maxDays}`) |
| GET | `/admin/metrics.php?type=resolution-cats` | admin | Pizza: categorias do mês atual |
| GET | `/admin/metrics.php?type=quality` | admin | Barras empilhadas: qualidade por mês |

### Regras de negócio

- `resolution_category_id` obrigatório em todo fechamento via `action=resolve`
- `resolution_notes` obrigatório (≥ 20 chars) quando slug é `not_reproducible` ou `duplicate`
- Fechamento como `duplicate` exige `duplicateOfId` e cria registro bidirecional em `ticket_relations`
- Fechamento em lote: ao fechar um chamado com duplicados abertos vinculados, o modal exibe checklist para fechar todos juntos
- Reabertura: apenas o dono do chamado pode reabrir; bloqueado se slug = `duplicate` ou prazo expirou; admin pode forçar sem restrições
- Vínculos são bidirecionais — ao criar `(A→B)`, cria-se também `(B→A)` na mesma transação
- `reopen_count` incrementado a cada reabertura; exibido como badge âmbar na fila admin

### Frontend (Sprint 3)

- **Modal de resolução**: categoria obrigatória, autocomplete para chamado original (se duplicado), notas condicionais, checklist de fechamento em lote
- **Detalhe do chamado — admin**: seção "Chamados Relacionados" com [+ Vincular] e remoção; "Fechar Chamado" substitui o dropdown para `resolved/closed`; "Forçar Reabertura" para full admin
- **Detalhe do chamado — usuário**: banner contextual (pode reabrir / duplicado / prazo expirado) com botão de ação
- **Dashboard**: 6 cards (+ Reaberturas + Taxa de Reabertura); gráfico "Qualidade de Resolução" (barras empilhadas); pizza categorias
- **Fila admin**: coluna "Reab." com badge âmbar quando `reopen_count > 0`
- **Aparência & White Label**: nova seção "Reabertura de Chamados" para configurar o prazo

---

## 10. Regras de Negócio Gerais

- `sla_hours` é snapshot — nunca recalculado após abertura do chamado
- `sla_breached` atualizado a cada request via `refreshSla()`
- `ticket_history` gravado a cada mudança de status, atribuição, resolução, reabertura ou vínculo
- Prioridade avaliada na abertura contra todas as `priority_rules` ativas
- Chamado prioritário **ou** com SLA vencido → linha destacada (fundo avermelhado)
- Uploads: máx. 5 arquivos por chamado, 5 MB total, só imagem ou PDF
- Senhas: bcrypt | Autenticação: sessão PHP (`session_name('HELPDESK')`)

---

## 11. Melhorias Futuras

- Reatribuição apenas por admin — atendente não pode transferir chamado
- Notificação por e-mail ao atendente na atribuição
- Se serviço não tiver `assignment_rule`, sinalizar no admin
- Chamados já atribuídos a atendente indisponível permanecem com ele (sem reatribuição automática)

---

## 10. Credenciais e Infraestrutura

| Item | Valor |
|---|---|
| URL | `poc.igorvilar.com.br/pjushelp` |
| FTP host | `31.170.166.157` |
| FTP user | `u665104129.igorvilar.com.br` |
| FTP path do projeto | `/poc/pjushelp/` |
| DB host | `localhost` (relativo ao servidor) |
| DB name | `u665104129_poc` |
| DB user | `u665104129_poc` |
