# Project identity

Open Warehouse Tracker (**OWT**) is a native Dolibarr warehouse extension. Its interface builds on Dolibarr's products, stock, lots/serials, users, documents and permissions; its private business service owns tracking rules and persistence.

Use the following names consistently when adding code, deployment files or documentation.

| Area                            | Name or contract                                                      |
| ------------------------------- | --------------------------------------------------------------------- |
| Product                         | Open Warehouse Tracker (OWT)                                          |
| Solution                        | `OpenWarehouseTracker.sln`                                            |
| Business service / namespace    | `OwtApi`, `src/OwtApi/OwtApi.csproj`                                  |
| Service tests                   | `tests/OwtApi.Tests`                                                  |
| Dolibarr module                 | `owt`, installed in `htdocs/custom/owt`                               |
| Module descriptor               | `modOwt`, Products family, module ID `503871`                         |
| Module activation / grants      | `MAIN_MODULE_OWT`, `$user->hasRight('owt', ...)`                      |
| Translation domain              | `owt@owt`, generated `OWTUI_` keys                                    |
| PHP helpers / Angular selectors | `owt_*`, `owt-*`                                                      |
| Service configuration           | `OWT_CONFIG_FILE`, `OWT_SERVICE_URL`, `OWT_SERVICE_API_KEY`           |
| Trusted actor headers           | `X-Owt-*`                                                             |
| Browser POST token              | `owt_token`                                                           |
| Location / physical-bin labels  | `OWTLOC:1:...`, `OWTBIN:1:...`                                        |
| Native receipt table            | `llx_owt_native_receipt` (using the configured Dolibarr table prefix) |
| Product serial-prefix field     | `owt_serial_prefix`                                                   |
| Dolibarr document storage       | `DOL_DATA_ROOT/owt/entity-N`                                          |
| Angular / npm / build output    | `owt`, `owt-web`, `frontend/dist/owt`                                 |
| Container service / image       | `owt-api`                                                             |
| Container volume / network      | `owt-data`, `owt`                                                     |
| Module archive                  | `module_owt-v<version>.zip`                                           |
| Service archive                 | `owt-service-v<version>.zip`                                          |

The rename assumes a fresh installation. These are the canonical contracts; there are no prior-name routes, settings aliases or migration adapters. Item IDs remain independent of branding and carrier: six- and eight-character checksummed identifiers are both accepted, with automatic range expansion as described in [barcodes](barcodes.md).

QR Code, Data Matrix, Code 128 and PDF417 are barcode standards, so their technical names and carrier identifiers remain unchanged. Repository hosting links use the actual GitHub repository URL.
