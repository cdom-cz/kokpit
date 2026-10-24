# Phase 12 Plan 08: Partner Permission Matrix, Owner Review Sheet

One page for the owner (D-02). The brief that held the original Partner permission matrix is not in the repository, so the matrix below was drafted from PROJECT.md, REQUIREMENTS.md and the code, and every difference found between the draft and the code is listed as a finding. Nothing here is confirmed yet. The sheet is generated from the three declaration files and the SUMMARYs of plans 12-01 to 12-07; the declarations stay the single source, this sheet is only their reading copy.

Declarations (plain data, never derived from the code under test, D-01):

- `tests/Support/PartnerMatrix.php`: screens, API endpoints, Livewire components, Horizon, Partner actions, Partner fields
- `tests/Support/RouteRules.php`: public and closed routes
- `tests/Support/ChannelMatrix.php`: the entity by channel cells and the audience of every notification and mail class

How to read it: "Admin" is the single admin account; "Partner" is a Partner account that belongs to a client (Partner A in the tests, client B being the other client). A Partner row of "no" means the Partner is refused there. The Audit suite (`ddev exec vendor/bin/pest --testsuite=Audit`, 592 tests, green) enforces exactly what the tables say, on every CI run.

How to answer (Task 2 of plan 12-08): reply "approve", or "amend" followed by a list in which every item names a row, a finding F-n or a question number and the intended behaviour. An amendment that contradicts a completed requirement (for example hiding the amounts of the Partner's own issued documents, IN-09) is not applied in this phase; it is recorded under "Deferred by the owner" for the roadmap.

Size of what is reviewed:

| Declaration | Rows |
|---|---|
| Panel classes (Resources, Pages, Widgets, relation managers; one test-only canary row) | 78 |
| `/api/v1` routes | 20 |
| Plain Livewire components | 3 |
| Horizon dashboard | 1 |
| Partner action declaration (entities that a Partner can see) | 8 entities, 107 ability rows |
| Admin-only entities (refused to a Partner on every action) | 30 |
| Partner field surfaces | 25 |
| Reasoned field exceptions | 4 |
| Public routes | 23 |
| Closed routes | 2 |
| Entities by channels (cells) | 38 by 8 = 304 cells, 79 of them not applicable |
| Notification and mail classes with an audience | 17 |
| Findings (F-1 to F-24) | 24 |
| Fixes made under D-09 | 2 production fixes |
| Open questions | 6 |

## Partner matrix

### Screens: Resources, Pages, Widgets, relation managers

Draft grouping as in plan 12-03. 78 rows, each checked in both directions against the discovered set of the panel and against the observed behaviour of an Admin and of a Partner with a client (including HTTP 200 or 403 on every Resource index and Page).

#### Pages for both roles

| Class | Admin | Partner | Reason |
|---|---|---|---|
| `Dashboard` | yes | yes | Landing page of both roles; a Partner sees neutral text and the Partner widgets only. |
| `EditProfile` | yes | yes | Every signed-in account edits its own profile and notification switches. |

#### Partner resources (the Admin uses the Admin resources instead)

| Class | Admin | Partner | Reason |
|---|---|---|---|
| `PartnerProjectResource` | no | yes | Read-only list of the own client's visible projects; opens only for a Partner with a client. |
| `PartnerTaskResource` | no | yes | Tasks of the own client's visible projects; opens only for a Partner with a client. |
| `PartnerInvoiceResource` | no | yes | Issued documents of the own client, read-only; opens only for a Partner with a client. |

#### Partner relation managers (observed on an own visible project or task)

| Class | Admin | Partner | Reason |
|---|---|---|---|
| `PartnerProjectDocumentsRelationManager` | yes | yes | Shared files of an own visible project, read-only. |
| `PartnerTaskCommentsRelationManager` | yes | yes | Non-internal comments of an own task and adding a comment. |
| `PartnerTaskDocumentsRelationManager` | yes | yes | Shared files of an own task. |

#### Partner widgets

| Class | Admin | Partner | Reason |
|---|---|---|---|
| `PartnerProjects` | no | yes | Own client's visible projects on the Partner dashboard; the widget refuses the Admin. |
| `PartnerTasks` | no | yes | Open tasks of the own client on the Partner dashboard; the widget refuses the Admin. |

#### Guest pages (reached only by their own guest or signed route)

| Class | Admin | Partner | Reason |
|---|---|---|---|
| `RequestPasswordReset` | no | no | Public forgot-password page; never opened through the panel checks. |
| `AcceptInvitation` | no | no | Invitation page; reached only through its own signed route. |

#### Admin resources

| Class | Admin | Partner | Reason |
|---|---|---|---|
| `ActivityResource` | yes | no | Audit trail of who changed what. |
| `ApiTokenResource` | yes | no | API tokens grant access to time and finance. |
| `CardPaymentResource` | yes | no | Card payments and their Stripe references are finance. |
| `ClientResource` | yes | no | Clients carry rates, terms and billing data. |
| `DocumentArchiveResource` | yes | no | ZIP archives bundle files of every client. |
| `DocumentResource` | yes | no | Central listing of the files of every client. |
| `ExchangeRateResource` | yes | no | Exchange rates feed invoice amounts. |
| `InvoiceResource` | yes | no | Billing documents carry rates, payments and drafts. |
| `ProjectResource` | yes | no | Projects carry rates, prices and billing terms. |
| `ReportExportResource` | yes | no | Report exports hold measured time and rates. |
| `TaskResource` | yes | no | Admin task screens carry people, billing and internal data. |
| `TimeEntryResource` | yes | no | Measured time is closed to Partners. |
| `TransactionCategoryResource` | yes | no | Categories of the finance module. |
| `TransactionResource` | yes | no | Income and expense are finance. |

#### Admin pages

| Class | Admin | Partner | Reason |
|---|---|---|---|
| `FinanceOverviewPage` | yes | no | Income, expense and balance. |
| `SettingsPage` | yes | no | Operator settings: supplier data, bank accounts, numbering. |
| `SignalDashboardPage` | yes | no | Overview of the personal planner of the Admin. |
| `SignalReflectionPage` | yes | no | Personal recaps of the Admin. |
| `SignalSettingsPage` | yes | no | Planner settings of the Admin. |
| `SignalTodayPage` | yes | no | Daily planner of the Admin. |
| `SignalWeekPage` | yes | no | Weekly goals and recap of the Admin. |
| `SystemPage` | yes | no | Queue, scheduler and failure state. |
| `TaskBoardPage` | yes | no | Board that moves cards across all clients. |
| `TimeReportPage` | yes | no | Measured time, rates and amounts. |
| `TimesheetPage` | yes | no | Measured time of the Admin. |
| `ProjectBoard` | yes | no | Board of one project; moves cards in the global column order. |

#### Admin widgets

| Class | Admin | Partner | Reason |
|---|---|---|---|
| `FinanceCategoryTable` | yes | no | Income and expense by category. |
| `FinanceClientTable` | yes | no | Income and expense by client. |
| `FinanceMonthsChart` | yes | no | Income and expense by month. |
| `FinanceStats` | yes | no | Income, expense and balance. |
| `ClientOverviewStats` | yes | no | Unbilled time and receivables of a client. |
| `DocumentStorageByClient` | yes | no | Stored volume per client. |
| `DocumentStorageStats` | yes | no | Stored volume across all clients. |
| `ExchangeRateStats` | yes | no | Whether the exchange rates are current. |
| `ProjectTimeStats` | yes | no | Worked and billed time of a project. |
| `HoursStats` | yes | no | Own measured time of the Admin. |
| `TodayPlan` | yes | no | Daily plan of the Admin. |
| `UnbilledPerClient` | yes | no | Unbilled time and amounts per client. |
| `UnpaidInvoices` | yes | no | Receivables, amounts and due dates. |
| `UnresolvedPayments` | yes | no | Unresolved card payments. |
| `UpcomingDeadlines` | yes | no | Task deadlines across all clients. |

#### Admin relation managers

| Class | Admin | Partner | Reason |
|---|---|---|---|
| `CardPaymentHistoryRelationManager` | yes | no | Change history of a card payment. |
| `ClientDocumentsRelationManager` | yes | no | All files of a client, hidden ones included. |
| `ClientHistoryRelationManager` | yes | no | Change history of a client. |
| `InvoiceDocumentsRelationManager` | yes | no | Files of a billing document, its PDF first. |
| `InvoiceHistoryRelationManager` | yes | no | Change history of a billing document. |
| `ProjectDocumentsRelationManager` | yes | no | Admin management of every project file. |
| `ProjectHistoryRelationManager` | yes | no | Change history of a project. |
| `TaskDocumentsRelationManager` | yes | no | Admin management of every task file. |
| `TaskHistoryRelationManager` | yes | no | Change history of a task. |
| `TimeEntryHistoryRelationManager` | yes | no | Change history of a time entry. |
| `TransactionDocumentsRelationManager` | yes | no | Receipts of a transaction. |
| `TransactionHistoryRelationManager` | yes | no | Change history of a transaction. |
| `ClientInvoicesRelationManager` | yes | no | Documents and amounts owed by a client. |
| `ClientProjectsRelationManager` | yes | no | Unbilled time and billing types of a client. |
| `ContactsRelationManager` | yes | no | Personal data of the contacts of a client. |
| `InvitationsRelationManager` | yes | no | Invitations that create Partner accounts. |
| `PartnerAccountsRelationManager` | yes | no | Partner accounts of a client. |
| `InvoiceEmailsRelationManager` | yes | no | Send log with recipients and bodies. |
| `InvoiceEntriesRelationManager` | yes | no | Time linked to a document, with rates and amounts. |
| `InvoicePaymentsRelationManager` | yes | no | Payments of a document. |
| `ProjectTasksTimeRelationManager` | yes | no | Worked time, estimates and billing state per task. |
| `ProjectTimeEntriesRelationManager` | yes | no | Measured time of a project. |
| `SubtasksRelationManager` | yes | no | Subtasks with the internal task fields. |
| `TaskCommentsRelationManager` | yes | no | Internal comments and the internal flag. |

#### Test-only

| Class | Admin | Partner | Reason |
|---|---|---|---|
| `CanaryRecordResource` | yes | yes | Canary resource of the isolation harness; registered only while the harness is on. |

### API endpoints

Every `/api/v1` route demands exactly one Sanctum ability. An Admin token with only that ability is not refused; a Partner token is answered 401 whatever abilities it carries.

| Route name | Ability | Admin | Partner | Reason |
|---|---|---|---|---|
| `api.v1.time-entries.index` | `time:read` | yes | no | Read of time and lookups. |
| `api.v1.time-entries.show` | `time:read` | yes | no | Read of time and lookups. |
| `api.v1.clients.index` | `time:read` | yes | no | Read of time and lookups. |
| `api.v1.clients.show` | `time:read` | yes | no | Read of time and lookups. |
| `api.v1.projects.index` | `time:read` | yes | no | Read of time and lookups. |
| `api.v1.projects.show` | `time:read` | yes | no | Read of time and lookups. |
| `api.v1.tasks.index` | `time:read` | yes | no | Read of time and lookups. |
| `api.v1.tasks.show` | `time:read` | yes | no | Read of time and lookups. |
| `api.v1.time-entries.store` | `time:write` | yes | no | Write of time entries. |
| `api.v1.time-entries.batch` | `time:write` | yes | no | Write of time entries. |
| `api.v1.time-entries.update` | `time:write` | yes | no | Write of time entries. |
| `api.v1.time-entries.destroy` | `time:write` | yes | no | Write of time entries. |
| `api.v1.transactions.index` | `finance:read` | yes | no | Read of finance. |
| `api.v1.transactions.show` | `finance:read` | yes | no | Read of finance. |
| `api.v1.transaction-categories.index` | `finance:read` | yes | no | Read of finance. |
| `api.v1.transaction-categories.show` | `finance:read` | yes | no | Read of finance. |
| `api.v1.transactions.store` | `finance:write` | yes | no | Write of finance. |
| `api.v1.transactions.batch` | `finance:write` | yes | no | Write of finance. |
| `api.v1.transactions.update` | `finance:write` | yes | no | Write of finance. |
| `api.v1.transactions.destroy` | `finance:write` | yes | no | Write of finance. |

### Livewire components and Horizon

| Component | Admin | Partner | Reason |
|---|---|---|---|
| `SignalDay` | yes | no | Daily planner card of the Admin. |
| `RecentEntriesPanel` | yes | no | Recent measured time entries of the Admin. |
| `TimerBar` | yes | no | Timer of the Admin. |

| Dashboard route | Prefix | Admin | Partner | Reason |
|---|---|---|---|---|
| `horizon.index` | `horizon.` | yes | no | The dashboard lists job payloads, so only the Admin may open it. |


### Partner actions per entity and record state

What a Partner with a client may do with each entity a Partner can see; the Admin may do everything. Record states: `own_visible` is a record of the own client that the Partner may see, `own_visible_other` is a second own visible record that differs in a way the ability cares about (a task assigned to the Admin, in a closed status), `own_upload` is a file the Partner uploaded, `own_hidden` is an own record hidden from the Partner, `foreign` is a record of the other client. A dash means the state does not apply to that ability. A class-level ability (viewAny, create; no record involved) shows its single answer in the first state column. For `attach` the record is the task the file would hang on.

| Entity | Ability | own_visible | own_visible_other | own_upload | own_hidden | foreign |
|---|---|---|---|---|---|---|
| `Project` | viewAny (class) | yes | - | - | - | - |
| `Project` | create (class) | no | - | - | - | - |
| `Project` | view | yes | - | - | no | no |
| `Project` | update | no | - | - | no | no |
| `Project` | delete | no | - | - | no | no |
| `Task` | viewAny (class) | yes | - | - | - | - |
| `Task` | create (class) | yes | - | - | - | - |
| `Task` | view | yes | yes | - | no | no |
| `Task` | comment | yes | yes | - | no | no |
| `Task` | escalate | yes | yes | - | no | no |
| `Task` | editDescription | yes | no | - | no | no |
| `Task` | clearEscalation | yes | no | - | no | no |
| `Task` | update | no | no | - | no | no |
| `Task` | delete | no | no | - | no | no |
| `TaskComment` | viewAny (class) | yes | - | - | - | - |
| `TaskComment` | create (class) | yes | - | - | - | - |
| `TaskComment` | view | yes | - | - | no | no |
| `TaskComment` | update | no | - | - | no | no |
| `TaskComment` | delete | no | - | - | no | no |
| `Document` | viewAny (class) | yes | - | - | - | - |
| `Document` | create (class) | no | - | - | - | - |
| `Document` | view | yes | - | yes | no | no |
| `Document` | update | no | - | no | no | no |
| `Document` | delete | no | - | yes | no | no |
| `Document` | attach | yes | no | - | - | no |
| `Invoice` | viewAny (class) | yes | - | - | - | - |
| `Invoice` | create (class) | no | - | - | - | - |
| `Invoice` | view | yes | - | - | no | no |
| `Invoice` | update | no | - | - | no | no |
| `Invoice` | delete | no | - | - | no | no |
| `InvoiceItem` | viewAny (class) | yes | - | - | - | - |
| `InvoiceItem` | create (class) | no | - | - | - | - |
| `InvoiceItem` | view | yes | - | - | no | no |
| `InvoiceItem` | update | no | - | - | no | no |
| `InvoiceItem` | delete | no | - | - | no | no |
| `Tag` | viewAny (class) | no | - | - | - | - |
| `Tag` | create (class) | no | - | - | - | - |
| `Tag` | view | no | - | - | - | no |
| `Tag` | update | no | - | - | - | no |
| `Tag` | delete | no | - | - | - | no |
| `CanaryRecord` | viewAny (class) | yes | - | - | - | - |
| `CanaryRecord` | create (class) | no | - | - | - | - |
| `CanaryRecord` | view | yes | - | - | - | no |
| `CanaryRecord` | update | no | - | - | - | no |
| `CanaryRecord` | delete | no | - | - | - | no |

Admin-only entities (viewAny, create, view, update, delete all refused to a Partner):

| Entity | Reason |
|---|---|
| `Client` | Clients carry rates, terms and billing data. |
| `Contact` | Personal data of the people of a client. |
| `ClientInvitation` | An invitation holds the invitee address and a token hash. |
| `ProjectBilling` | Rates, prices, estimate and the internal note of a project. |
| `TaskChecklistItem` | The private working list of the Admin on a task. |
| `TaskBilling` | Rates, prices and the internal note of a task. |
| `TimeEntry` | Measured time is closed to a Partner. |
| `InvoiceAdmin` | Internal note, conversion snapshot and PDF link of a billing document. |
| `InvoiceItemSource` | Rates and seconds a printed line was generated from. |
| `InvoicePayment` | Payments received for a billing document. |
| `PaymentLink` | The card payment link of a document with its references. |
| `CardPayment` | Card payment records and their provider references. |
| `InvoiceEmail` | The send log of a document with recipients and bodies. |
| `PaymentConfirmation` | The confirmation sent for a payment. |
| `ExchangeRate` | Exchange rates feed the converted amounts. |
| `TransactionCategory` | Categories of the finance module. |
| `Transaction` | Income and expense are finance. |
| `DocumentArchive` | ZIP archives bundle the files of every client. |
| `ReportExport` | Report exports hold measured time and rates. |
| `SignalTask` | Personal planner of the Admin. |
| `SignalRecurringTask` | Personal planner of the Admin. |
| `SignalSetting` | Personal planner of the Admin. |
| `SignalDeepWorkDay` | Personal planner of the Admin. |
| `SignalDayOverride` | Personal planner of the Admin. |
| `SignalWeeklyGoal` | Personal planner of the Admin. |
| `SignalWeeklyRecap` | Personal planner of the Admin. |
| `Media` | Stored objects reach a Partner only through the document rows. |
| `Activity` | The audit trail of who changed what. |
| `WebhookCall` | Incoming webhook payloads from the payment provider. |
| `SettingsProperty` | Operator settings: supplier data, bank accounts, numbering. |


### Partner fields per surface

The names a Partner sees in each table, detail, form and filter of the Partner screens (runtime names must equal the list). A name that matches the finance or measured-time word pattern (rate, price, estimate, billing, billable, internal, cnb, czk, exchange, payment, source, cost, budget, unbilled, hours, duration, minute, seconds, tracked, measured, spent, worked, elapsed, time) fails the Audit suite unless it is listed below with a written reason.

| Surface | Fields the Partner sees |
|---|---|
| `PartnerProjectResource.table` | `name`, `key`, `status`, `priority`, `start_date`, `end_date`, `tags` |
| `PartnerProjectResource.infolist` | `name`, `key`, `status`, `description`, `start_date`, `end_date`, `priority`, `tags` |
| `PartnerProjectResource.form` | (none) |
| `PartnerProjectResource.filters` | (none) |
| `PartnerTaskResource.table` | `reference`, `title`, `project.key`, `status`, `priority`, `due_date`, `assignee.name` |
| `PartnerTaskResource.infolist` | `reference`, `title`, `project.key`, `status`, `priority`, `start_date`, `due_date`, `assignee.name`, `requester.name`, `parent.reference`, `description`, `escalated_at` |
| `PartnerTaskResource.form` | `project_id`, `title`, `description`, `files` |
| `PartnerTaskResource.filters` | (none) |
| `PartnerInvoiceResource.table` | `number`, `kind`, `issue_date`, `due_date`, `total`, `remaining`, `status` |
| `PartnerInvoiceResource.infolist` | `number`, `kind`, `issue_date`, `taxable_supply_date`, `due_date`, `variable_symbol`, `currency`, `supplier`, `customer`, `payment_method`, `bank_account`, `payment_variable_symbol`, `amount_due`, `payment_due_date`, `lines`, `description`, `quantity`, `unit_price`, `line_total`, `summary_total`, `summary_paid`, `summary_remaining`, `note`, `related_credit_note`, `related_invoice` |
| `PartnerInvoiceResource.form` | (none) |
| `PartnerInvoiceResource.filters` | `kind`, `status` |
| `PartnerProjectDocumentsRelationManager.table` | `name`, `description`, `size_bytes`, `created_at` |
| `PartnerProjectDocumentsRelationManager.filters` | (none) |
| `PartnerProjectDocumentsRelationManager.form` | (none) |
| `PartnerTaskDocumentsRelationManager.table` | `name`, `description`, `size_bytes`, `created_at` |
| `PartnerTaskDocumentsRelationManager.filters` | (none) |
| `PartnerTaskDocumentsRelationManager.form` | (none) |
| `PartnerTaskCommentsRelationManager.table` | (none) |
| `PartnerTaskCommentsRelationManager.filters` | (none) |
| `PartnerTaskCommentsRelationManager.form` | `body`, `files` |
| `PartnerProjects.table` | `name`, `key`, `status`, `priority`, `end_date` |
| `PartnerProjects.filters` | (none) |
| `PartnerTasks.rows` | `id`, `reference`, `title`, `status`, `due_date`, `project_id`, `assignee_id`, `project.id`, `project.name`, `assignee.id`, `assignee.name` |
| `EditProfile.form` | `name`, `email`, `password`, `passwordConfirmation`, `currentPassword`, `notifications.comment.mail`, `notifications.comment.database`, `notifications.escalation.mail`, `notifications.escalation.database`, `notifications.assignment_change.mail`, `notifications.assignment_change.database` |

Reasoned field exceptions (a name that matches the finance and measured-time word pattern, allowed all the same):

| Surface#field | Reason |
|---|---|
| `PartnerInvoiceResource.infolist#unit_price` | The unit price of an item printed on the own issued document; the Partner reads the whole printed document (IN-09, research A5). |
| `PartnerInvoiceResource.infolist#payment_method` | The payment method printed on the own issued document (transfer or online); a payment instruction, not a payment record (IN-09). |
| `PartnerInvoiceResource.infolist#payment_variable_symbol` | The variable symbol printed in the payment instructions of the own issued document (IN-09). |
| `PartnerInvoiceResource.infolist#payment_due_date` | The due date printed in the payment instructions of the own issued document (IN-09). |

Partner screens with no field surface:

| Class | Reason |
|---|---|
| `Dashboard` | The Dashboard shows neutral text and the Partner widgets only; the widgets carry their own surfaces. |
| `CanaryRecordResource` | Test-only canary resource of the isolation harness; it is no screen of the product. |


## Routes

The public allowlist carries the means that protects each route instead of a login; a route that is not authenticated and not listed here fails the Audit suite, and so does a listed route that does not exist. An unnamed route is keyed `METHOD /uri` with the Livewire prefix written `livewire-{hash}` (open question 3).

| Key | Means | Reason |
|---|---|---|
| `GET /` | none | entry redirect to the panel, returns no data |
| `GET /up` | none | health probe for the load balancer, returns no data |
| `pay.show` | token | stable pay URL printed on the PDF and sent by e-mail; the unguessable token is the only key, throttled |
| `webhooks.stripe` | signature | payment provider webhook intake, signature verified before any write, throttled |
| `filament.admin.auth.login` | guest-only | panel login page |
| `filament.admin.auth.password-reset.request` | guest-only | forgot-password page, throttled |
| `filament.admin.auth.password-reset.reset` | signature | password reset link |
| `filament.admin.invitation.accept` | signature | Partner invitation link, throttled |
| `scribe` | none | public API documentation by owner decision (config/scribe.php) |
| `scribe.openapi` | none | public API documentation by owner decision (config/scribe.php) |
| `scribe.postman` | none | public API documentation by owner decision (config/scribe.php) |
| `sanctum.csrf-cookie` | none | Sanctum SPA cookie route, sets only the XSRF cookie, unused by the application |
| `storage.local` | signature | framework local-disk serving, temporary signed URLs only |
| `storage.local.upload` | signature | framework local-disk upload, temporary signed URLs only |
| `default-livewire.update` | component | Livewire endpoint; every component checks access on mount and on every update (panel persistent middleware) |
| `livewire.upload-file` | signature | Livewire temporary upload, signed URL only |
| `livewire.preview-file` | signature | Livewire temporary file preview, signed URL only |
| `GET /livewire-{hash}/livewire.js` | none | static Livewire script asset |
| `GET /livewire-{hash}/livewire.min.js.map` | none | static Livewire script source map |
| `GET /livewire-{hash}/livewire.csp.min.js.map` | none | static Livewire script source map |
| `GET /livewire-{hash}/js/{component}.js` | none | static Livewire component script asset |
| `GET /livewire-{hash}/css/{component}.css` | none | static Livewire component style asset |
| `GET /livewire-{hash}/css/{component}.global.css` | none | static Livewire global component style asset |

Closed routes (answer the declared status to everyone):

| Key | Status | Reason |
|---|---|---|
| `filament.exports.download` | 404 | Filament export action route; the application uses no export action and its table does not exist |
| `filament.imports.failed-rows.download` | 404 | Filament import action route; the application uses no import action and its table does not exist |


## Channel cells

The eight channels are ui, search, selects, exports, notifications, activity, downloads and api. Every entity of the canary registry has one cell per channel: `foreign` (Partner A sees the own client's rows, never client B's), `none` (Partner A sees the rows of neither client) or not applicable with a written reason. A wrong not-applicable cell would hide a leak, so the 79 not-applicable cells are listed in full below by channel; the remaining 225 cells are `none` or `foreign` and are read from `tests/Support/ChannelMatrix.php`.

Cell corrections made while driving the channels (draft cell versus observed): none. All 38 ui cells, 38 search, 38 selects, 13 exports, 38 notifications, 15 activity, 7 downloads and 38 api cells held as declared.

| Channel | Reason | Entities |
|---|---|---|
| exports | no export reads this model | `CanaryRecord`, `Contact`, `ClientInvitation`, `TaskChecklistItem`, `TaskComment`, `InvoiceAdmin`, `InvoiceItemSource`, `InvoicePayment`, `PaymentLink`, `CardPayment`, `InvoiceEmail`, `PaymentConfirmation`, `ExchangeRate`, `SignalTask`, `SignalRecurringTask`, `SignalSetting`, `SignalDeepWorkDay`, `SignalDayOverride`, `SignalWeeklyGoal`, `SignalWeeklyRecap`, `Media`, `Tag`, `Activity`, `WebhookCall`, `SettingsProperty` (25) |
| activity | writes no activity rows: not in AuditDeclaration::loggingModels() | `CanaryRecord`, `ClientInvitation`, `TaskChecklistItem`, `TaskComment`, `InvoiceItem`, `InvoiceItemSource`, `InvoiceEmail`, `PaymentConfirmation`, `ExchangeRate`, `TransactionCategory`, `DocumentArchive`, `ReportExport`, `SignalTask`, `SignalRecurringTask`, `SignalSetting`, `SignalDeepWorkDay`, `SignalDayOverride`, `SignalWeeklyGoal`, `SignalWeeklyRecap`, `Media`, `Tag`, `WebhookCall`, `SettingsProperty` (23) |
| downloads | no file is stored for or generated from this model; files leave only through the document, archive, invoice PDF and report export routes | `CanaryRecord`, `Client`, `Contact`, `ClientInvitation`, `Project`, `ProjectBilling`, `Task`, `TaskChecklistItem`, `TaskBilling`, `TaskComment`, `InvoiceAdmin`, `InvoiceItemSource`, `InvoicePayment`, `PaymentLink`, `CardPayment`, `InvoiceEmail`, `PaymentConfirmation`, `ExchangeRate`, `TransactionCategory`, `Transaction`, `SignalTask`, `SignalRecurringTask`, `SignalSetting`, `SignalDeepWorkDay`, `SignalDayOverride`, `SignalWeeklyGoal`, `SignalWeeklyRecap`, `Tag`, `Activity`, `WebhookCall`, `SettingsProperty` (31) |

Not-applicable cells in total: 79 of 304.

Foreign cells (Partner A may see the own client's rows, never client B's):

| Entity | Channels |
|---|---|
| `CanaryRecord` | ui, search |
| `Project` | ui, selects, notifications |
| `Task` | ui, notifications |
| `TaskComment` | ui, notifications |
| `Document` | ui, downloads |
| `Invoice` | ui, downloads |
| `InvoiceItem` | ui, downloads |
| `Tag` | ui |

Notification and mail class audiences:

| Class | Audience | Reason |
|---|---|---|
| `TaskCreatedNotification` | partner | sent through TaskNotifier to the Admin and to the Partner requester or assignee, with the my-tasks link for a Partner |
| `TaskCommentedNotification` | partner | sent through TaskNotifier to the Admin and to the Partner requester or assignee, with the my-tasks link for a Partner |
| `TaskChangedNotification` | partner | sent through TaskNotifier to the Admin and to the Partner requester or assignee, with the my-tasks link for a Partner |
| `TaskEscalatedNotification` | partner | sent through TaskNotifier to the Admin and to the Partner requester or assignee, with the my-tasks link for a Partner |
| `ResetPasswordNotification` | any-user | password reset of any user, a Partner included; carries only the reset link |
| `PartnerInvitation` | client-mail | sent to the invited address before an account exists; carries only the signed invitation link |
| `InvoiceMail` | client-mail | sent to the client's invoice e-mail address; carries the client's own issued document |
| `PaymentConfirmationMail` | client-mail | sent to the client's invoice e-mail address; carries the client's own payment confirmation |
| `PartnerUploadedFilesNotification` | admin | tells the Admin that a Partner uploaded files, with a count and no file name |
| `DocumentArchiveReadyNotification` | admin | tells the Admin that a document archive is ready |
| `DocumentArchiveFailedNotification` | admin | tells the Admin that building a document archive failed |
| `ReportExportReadyNotification` | admin | tells the Admin that a report export is ready; reports are measured time and finance |
| `ReportExportFailedNotification` | admin | tells the Admin that a report export failed |
| `InvoiceEmailFailedNotification` | admin | tells the Admin that sending an invoice e-mail failed; invoices are finance |
| `CardPaymentMatchedNotification` | admin | tells the Admin that a card payment was matched; payments are finance |
| `LongRunningTimerNotification` | admin | tells the Admin that a timer runs long; measured time is closed to a Partner |
| `OperationalAlert` | admin | operational alert for the Admin |


## Findings

Every difference between the draft and the code, and every judgment call left to the owner, found in plans 12-01 to 12-07. Each row is declared as the code behaves (R-12-03-3) unless the column "What the plan did" says it was fixed. Count by source: plans 12-01 and 12-02 3 rows, 12-03 6 rows, 12-04 8 rows, 12-05 2 rows, 12-06 2 rows, 12-07 3 rows, 24 in total.

| ID | Item | Draft | Observed | Source | What the plan did |
|---|---|---|---|---|---|
| F-1 | An `/api/v1` request without an `Accept` header, from a guest or from a signed-in Admin or Partner session | Answers a JSON 401 | Answered 500 ("Route [login] not defined") on all 20 routes | 12-02 defect 1 | Fixed under D-09, see Fix 1. The plain variant of R-12-02-2 was enough: `redirectGuestsTo` returns the panel login for every request, and the existing JSON handling answers 401 for `api/*`. |
| F-2 | The Filament export and import download routes `filament.exports.download` and `filament.imports.failed-rows.download` | Declared closed, answer 404 | Answered 500 for a guest, a Partner and an Admin, because their tables do not exist | 12-02 defect 2 | Fixed under D-09, see Fix 2. Closed by explicit binders (R-12-02-3), see open question 5. |
| F-3 | Recogniser of an authentication middleware (R-12-01-2) | A resolved middleware "starts with" `Filament\Http\Middleware\Authenticate` | `AuthenticateSession` shares that prefix and sits on every panel route, so prefix matching would have treated every panel route, the login page included, as authenticated | 12-01 deviation 1, 12-02 next readiness | Corrected in the test code: the class name must match exactly or be followed by a colon (the `auth:sanctum` form). The wording of R-12-01-2 reads "is exactly, or is followed by a colon". No production change. |
| F-4 | `PartnerProjects` and `PartnerTasks` widgets | Admin "as observed" | The Admin is refused by `canView`; a Partner with a client is admitted | 12-03, widget class docs | Declared Admin no, Partner yes |
| F-5 | `PartnerProjectDocumentsRelationManager`, `PartnerTaskCommentsRelationManager`, `PartnerTaskDocumentsRelationManager` | Admin "as observed" | Admin admitted on an own visible project or task of client A, Partner admitted | 12-03, declaration plus the parent check on the owner record | Declared Admin yes, Partner yes |
| F-6 | `UnresolvedPayments` widget | Admin yes | The Admin sees it only while at least one unresolved card payment exists; without one `canView()` is false | 12-03, `UnresolvedPayments::canView()` | Declared Admin yes; the per-row test seeds one unmatched payment. Owner to confirm that data-dependent visibility is intended. **Confirmed by the owner on 2026-10-24: data-dependent visibility is intended.** |
| F-7 | `ProjectBoard` | Admin yes, Partner no (an Admin page) | As drafted, but the older panel registry test skips it because it is a resource page; it carries its own access rule | 12-03, `ProjectBoard` own rule | Declared as drafted; the inventory counts resource pages that carry their own rule |
| F-8 | Guest pages `RequestPasswordReset`, `AcceptInvitation` | no, no | Denied to everyone by the panel checks; reached only by their own guest or signed route (covered by the route declaration) | 12-03, guest access rule | Declared no, no; not requested over HTTP as a panel page |
| F-9 | Class count | "about eighty" | 81 classes by the 12-03 SUMMARY: the declaration holds 78 panel rows plus the 3 Livewire components. The completeness check passes both ways, so no class is left over | 12-03 | One literal row per class |
| F-10 | Task `own_visible_other` (the task of "another person") | A task with no assignee | The Admin is assigned: `tasks.assignee_id` is NOT NULL, a task without an assignee cannot exist | 12-04, failed null assignment | Row built with the Admin as requester and assignee; `editDescription` and `clearEscalation` are refused on it as drafted |
| F-11 | `PartnerInvoiceResource.infolist#summary_paid` | Rules list "totals, remaining amount, status" and forbid "payments" | The detail shows a "paid" amount, the sum of the document's payments (the payment rows themselves stay Admin-only) | 12-04, `InvoiceColumns::partnerEntries()` | Declared as shown, not removed. Total minus remaining gives the same number, so it discloses nothing the Partner cannot compute. Owner to confirm that a paid amount is intended (open question 2). **Confirmed by the owner on 2026-10-24: the paid amount is intended.** |
| F-12 | `PartnerInvoiceResource.infolist#unit_price` | Allowed by IN-09 (research A5) | Present, matches the word price | 12-04, items table of the detail | Declared with a reason in the field exceptions. Owner to confirm this reading of "prices" (open question 2). **Confirmed by the owner on 2026-10-24: this reading of "prices" is intended.** |
| F-13 | `PartnerInvoiceResource.infolist#payment_method`, `#payment_variable_symbol`, `#payment_due_date` | Printed bank box and pay link allowed | Present, match the word payment, but they are payment instructions printed on the document | 12-04, payment section of the detail | Declared with a reason each in the field exceptions |
| F-14 | `PartnerTasks.rows` (Partner tasks widget) | Name of project and assignee, reference, title, status, due date | The query also selects `id`, `project_id`, `assignee_id`, `project.id` and `assignee.id` (record keys, among them the Admin's account id) | 12-04, `PartnerTasks::tasks()` select list | Declared as selected. The keys are identifiers, not finance or time fields. Owner to decide whether the account id of the assignee should stay in the widget snapshot. **Confirmed by the owner on 2026-10-24: the keys stay in the widget snapshot as implemented.** |
| F-15 | `PartnerTaskCommentsRelationManager.table` | Table with author, text and date | No table: a thread view replaces it, so the table has no column; the inline form has `body` and `files` | 12-04, comment thread view | Declared as an empty table and a two-field form. The author, text and date are rendered by a view that a schema cannot list; the page-level canary walk of plan 12-06 covers what that view shows. |
| F-16 | `CanaryRecordResource` | Partner screen | Test-only resource of the harness, no product screen | 12-04 | Exempt from field surfaces by a written reason |
| F-17 | Ability set | list, view, create, update, delete, custom abilities | restore, forceDelete, replicate, reorder and the bulk abilities are not asserted | 12-04, plan scope | The base policy denies them; add rows if the owner wants them in the matrix |
| F-18 | Shared canary text in the channel drivers (search, selects, api, activity) | One needle per entity | Many entities share the same canary string (a project name, a task title, a client name and others), so the text alone cannot tell which entity a result shows | 12-05, attribution (R-12-05-3) | The assertion is kept in full and the result is attributed to the entity that produced it: search by the resource category, selects by the primary key of an option, api and activity by the whole output. Related to F-20. |
| F-19 | Selects driver coverage | Every select a Partner reaches | Relation manager header-action forms (for example a comment form) are not mounted, only their table filters. The Partner relation managers and widgets carry no select today; a future one is walked automatically | 12-05, selects driver | Declared as a limit of the driver, no change |
| F-20 | A shared canary text limits what a `none` ui cell proves | A `none` ui cell proves the entity is nowhere in the Partner's pages | A client name, a contact name, a project name and a task title are the same canary string, so a page cannot tell which entity printed it. `Client x ui` proves the `clients` resource and every unlisted page are closed to Partner A; it does not prove the own client name is absent from a project page of the Partner | 12-06 | No change. Closing the blind spot would need a distinct text per entity in the canary registry, which touches the plan 12-05 fixtures. Owner to decide whether the residual blind spot is acceptable. **Confirmed by the owner on 2026-10-24: no distinct canary text per entity, kept as implemented.** |
| F-21 | Defence in depth hid a rule mutation | One declaration is the gate | Changing the access rule of `TimeReportPage` from admin-only to Partner-allowed did not make Partner A reach the page (still 403), so another layer refuses it too | 12-06 | Nothing to fix; the exports driver itself is proved by running it as the Admin. Noted because one declaration alone is not the only gate. |
| F-22 | The retitled canary task in the notifications driver | A `none` notification cell fails on any appearance of the canary | The shared canary text would make every `none` cell fail on the legitimate task title in a mail, so the driver retitles the canary task before the script runs. A leak of a value checked only by the `foreign` cells of Project, Task and TaskComment cannot be told apart from a legitimate appearance by this channel alone | 12-07 | No change; the isolation suite (distinct canaries per field) covers that and is unchanged |
| F-23 | Four notification and mail classes are not run by the script | A run proves each audience | `ResetPasswordNotification`, `PartnerInvitation`, `InvoiceMail` and `PaymentConfirmationMail` are labelled from reading their code and the plan table; a password reset to a Partner is by design (any-user), and the two mails and the invitation go to addresses, not to a bell | 12-07 | The audit proves the labels are present and that no `admin` class reaches Partner A; it does not prove the content of these four. Owner to confirm the labels. **Confirmed by the owner on 2026-10-24: the labels are correct.** |
| F-24 | A Partner escalation reaches a Partner only through a colleague | A Partner is told of every escalation | Partner A escalating the own task tells the Admin; Partner A is told of an escalation only when a colleague Partner (or the Admin, when allowed) escalates a task assigned to Partner A | 12-07 | The script includes a colleague Partner for that reason; no change |

## Fixes made

Every Partner-facing or robustness defect the audit found and fixed under D-09. No leak of Partner data was found by plans 12-03 to 12-07 (every Partner action, field, channel, notification and download walk held as declared), so the two fixes below are the only production-code changes of the phase (plan 12-01 also added CI configuration and composer scripts, see open question 4).

| Fix | Finding | File | Regression test | Commits |
|---|---|---|---|---|
| Fix 1: `redirectGuestsTo` returns the panel login route, so an unauthenticated `/api/v1` request without an `Accept` header answers a JSON 401 instead of 500 | F-1 | `bootstrap/app.php` | `tests/Audit/RouteAuditTest.php`: "defect 1: answers 401 JSON to a guest on every /api/v1 route that sends no Accept header", "defect 1: answers 401 to a signed-in Admin and Partner session on every /api/v1 route that sends no Accept header"; guard: "keeps the Filament login redirect and the Horizon 403 for a guest" | `bf8d7a8` (test), `e2f943c` (fix) |
| Fix 2: explicit route binders for `export` and `import` abort 404, so the unused Filament download routes answer 404 to everyone instead of 500 | F-2 | `app/Providers/AccessServiceProvider.php` | `tests/Audit/RouteAuditTest.php`: "defect 2: closes the Filament export and import download routes with 404 for a guest, a Partner and an Admin"; the closed-route walk "answers the declared status on closed route {0}" | `6c2be58` (test), `42f6605` (fix), `737077a` (walks) |

Test-code fix without a production change: the authentication recogniser of the route inventory (F-3), in `tests/Support/RouteInventory.php` with its self-test "recognises an authentication middleware exactly, not by prefix" (commit `9ab15ca`).

## Open questions

The six open questions of 12-RESEARCH.md, each with the default the plans implemented. Nothing was decided silently: the default is what runs today and what the Audit suite enforces. Approve keeps all six; amend names the question number and the intended behaviour.

| Q | Question | Implemented default | Where it lives | What an amendment would change |
|---|---|---|---|---|
| 1 | The permission matrix itself (D-02) | As drafted in plans 12-03 and 12-04, with the findings F-4 to F-24 resolved as listed above | The tables of "Partner matrix", `tests/Support/PartnerMatrix.php` | The row, plus the code where behaviour changes, plus the row as regression |
| 2 | What "prices" means for the Partner's own invoices (research A5) | The totals, remaining amount, item unit price and item amount of an own issued document stay visible to the Partner (IN-09), declared as reasoned field exceptions (F-11, F-12, F-13). Rates, project and task prices, estimates, CNB/CZK conversions, payments, drafts and internal notes stay hidden | `fieldExceptions()` in `PartnerMatrix.php`; `InvoiceColumns::partnerEntries()` | Hiding amounts of the Partner's own issued documents contradicts IN-09 (complete), so it would be deferred to the roadmap, not applied here. Narrower amendments (for example dropping the paid amount) can be applied with their row. |
| 3 | The allowlist key of an unnamed route (D-06 says "route name") | The route name when present, else `METHOD /uri` with the Livewire prefix normalised to `livewire-{hash}` (R-12-01-1); the six unnamed Livewire asset routes are six explicit keys, not a wildcard | `RouteRules.php`, `RouteInventory.php` | The key scheme and the guard tests of R-12-01-1 |
| 4 | CI shape | The Audit suite is excluded from `composer test` and the main Pest step and runs in its own `Partner audit` step and in `composer ci` (R-12-01-4); a plain `vendor/bin/pest` still runs every suite. The pass of that step on a real hosted CI run has not been observed locally | `composer.json`, `.github/workflows/hygiene.yml`, `tests/Feature/Repo/CiParityTest.php` | The scripts, the workflow step and the parity test |
| 5 | The unused Filament export and import download routes (F-2) | Closed with 404 by explicit binders (R-12-02-3) instead of creating their tables | `app/Providers/AccessServiceProvider.php`, `RouteRules::closedRoutes()` | The binders and the closed-route declaration |
| 6 | DO-01 (files, pending checkbox in REQUIREMENTS.md) | Not this phase's scope. The downloads channel relies on the current file rules, which the existing route walk and document visibility tests already cover; the owner may confirm DO-01 separately (for example with `/gsd-verify-work 9`) | `REQUIREMENTS.md` (DO-01 pending, traceability "Gaps Found") | Nothing in this phase; DO-01 stays with phase 9 |

## Owner decision

Status: approved

Date: 2026-10-24

Answer of the owner, paraphrased: the owner first asked to amend, then asked for the list of items that need a decision and answered it item by item. No item changes behaviour, so the decision is recorded as approved, not amended.

- F-6 (`UnresolvedPayments` visible to the Admin only while an unresolved card payment exists): confirmed as implemented.
- F-11 (paid amount in the Partner invoice detail): confirmed as implemented.
- F-12 (item unit price in the Partner invoice detail): confirmed as implemented.
- F-14 (record keys in the `PartnerTasks` widget snapshot): confirmed as implemented.
- F-20 (shared canary text limits what a `none` ui cell proves): keep as implemented, no distinct canary text per entity.
- F-23 (labels of the four notification and mail classes not run by the script): confirmed as implemented.
- Open questions 1 to 6: the implemented defaults stand. Question 6 (DO-01) stays out of scope of this phase and with phase 9.

Applied changes: none to production code and none to the declaration rows. The only edits are the confirmation line in the docblocks of `tests/Support/PartnerMatrix.php`, `tests/Support/RouteRules.php` and `tests/Support/ChannelMatrix.php`, and the "confirmed by the owner" marks on F-6, F-11, F-12, F-14, F-20 and F-23 above.

Deferred by the owner: nothing.
