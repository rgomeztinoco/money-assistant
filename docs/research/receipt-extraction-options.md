# Receipt extraction options under the privacy boundary

_Researched 2026-07-22 for the single-user, self-hosted Money Assistant MVP._

## Decision summary

Use a **local-first, replaceable extraction pipeline** for the MVP. Keep the receipt image and first-pass OCR/structure extraction on the local server, and never accept extracted monetary data without deterministic reconciliation against the known parent Transaction total. Start by benchmarking PaddleOCR/PP-Structure locally and Microsoft's connected Document Intelligence Receipt container as the receipt-specialized local candidate. Tesseract is useful as a lightweight baseline, but not as the primary structured extractor.

Do not use a cloud receipt API or a cloud vision model unless a separate decision explicitly permits receipt-image egress. The standing rule that only extracted fields may be sent to cloud AI does **not** authorize sending a receipt image. If image egress is later approved, Google Document AI Expense Parser is the most clearly documented fit for Spanish receipts; Azure Document Intelligence Receipt is another strong structured candidate. AWS Textract AnalyzeExpense is inexpensive, but its documented content-use default requires an explicit AWS Organizations opt-out and its official AnalyzeExpense documentation does not establish a Spanish-receipt support guarantee.

The official sources reviewed do not publish an apples-to-apples accuracy result for the actual target—photographed Peruvian supermarket and retail receipts in Spanish, with PEN and USD amounts. The provider choice therefore must follow a small labeled benchmark, not marketing accuracy claims.

## Privacy boundary

A receipt image can expose much more than the Line Items needed by the application: store location, time, loyalty identifier, partial card data, tax identifiers, and sometimes a person's name. Treat the image and complete OCR result as sensitive source material.

| Approach | What leaves the server | Compatible with the current boundary? |
| --- | --- | --- |
| Tesseract or PaddleOCR running locally | Nothing at inference time; model/package downloads happen during installation | Yes |
| Azure Document Intelligence Receipt connected container | Metering/billing data; Microsoft states Azure AI containers do not send the image or analyzed text to Microsoft | Yes, provided outbound metering is acceptable |
| Fully disconnected Azure container | Nothing during operation, but it is a separately licensed/approved commitment-tier offering | Potentially, but disproportionate for an MVP |
| Google Expense Parser, Azure cloud Receipt, AWS AnalyzeExpense, or a cloud vision model | The complete receipt image plus request metadata; the result returns from the provider | No—not without explicit receipt-image permission |
| Local OCR followed by cloud categorization | Only the deliberately minimized extracted fields sent by the application | Yes, under the already agreed restriction |

Microsoft documents that its v3.1 Receipt container runs on premises, requires a supporting Read or Layout container, must contact Azure for billing every 10–15 minutes, and does not send customer images or text to Microsoft. Receipt is not yet one of the v4.0 container models. The container is billed at the same rate as the cloud service. [Install and run Document Intelligence containers](https://learn.microsoft.com/en-us/azure/ai-services/document-intelligence/containers/install-run?tabs=business-card&view=doc-intel-3.0.0) [Container configuration](https://learn.microsoft.com/en-us/azure/ai-services/document-intelligence/containers/configuration?view=doc-intel-4.0.0)

For direct cloud calls:

- Google states that synchronous `processDocument` requests are processed in memory and are not persisted to disk, while batch documents have a failsafe retention of up to one day. It does not use Document AI customer content to train its models. Processing/storage location must be selected, although the Expense Parser is available only in the regions listed for that processor. [Document AI security and data usage](https://docs.cloud.google.com/document-ai/docs/security) [Document AI regions](https://docs.cloud.google.com/document-ai/docs/regions)
- Azure temporarily encrypts and stores both input and results in the resource's region. Analyze results remain retrievable for 24 hours unless explicitly deleted sooner. [Document Intelligence data privacy and security](https://learn.microsoft.com/en-us/azure/foundry/responsible-ai/document-intelligence/data-privacy-security?view=form-recog-3.0.0)
- AWS accepts the receipt as image bytes or an S3 object in the same AWS Region. Its current service terms permit Amazon Textract content to be stored and used for service improvement, potentially outside the service Region, unless an AWS Organizations AI-services opt-out policy is applied. [AnalyzeExpense input](https://docs.aws.amazon.com/textract/latest/APIReference/API_AnalyzeExpense.html) [AWS Service Terms, section 50.3](https://aws.amazon.com/service-terms/) [AI-services opt-out policies](https://docs.aws.amazon.com/organizations/latest/userguide/orgs_manage_policies_ai-opt-out.html)

Even after local OCR, the application should minimize the cloud categorization payload to item description, amount, quantity/unit if needed, merchant context, and currency. It should remove addresses, receipt numbers, tax identifiers, loyalty data, and card digits unless a later feature has a specific need for them.

## Candidate capabilities and trade-offs

### 1. PaddleOCR / PP-Structure: recommended open-source baseline

PaddleOCR is Apache-2.0 licensed, supports local CPU and GPU deployment, multilingual OCR including Spanish/Latin scripts, and structured document parsing. PP-Structure supports layout analysis, table recognition, key-information extraction, and coordinates; the current PaddleOCR project also provides document-oriented vision-language and structure pipelines with JSON/Markdown output. [PaddleOCR source repository](https://github.com/PaddlePaddle/PaddleOCR) [PP-Structure overview](https://paddlepaddle.github.io/PaddleOCR/v2.10.0/en/ppstructure/overview.html) [PaddleOCR quick start](https://paddlepaddle.github.io/PaddleOCR/main/en/quick_start.html)

Advantages:

- The image, complete OCR, bounding boxes, and corrections stay local.
- No per-page fee or cloud account is required.
- Bounding boxes and text confidence make a review UI and reprocessing possible.
- It offers more layout awareness than plain OCR and can later be fine-tuned or paired with merchant-specific parsers.

Constraints:

- It is a document toolkit, not a guaranteed Peruvian-receipt schema. The application still needs line grouping, amount parsing, summary/discount recognition, and reconciliation logic.
- Key-information extraction can require labeled training for the target entities. A general document benchmark score is not a receipt Line Item accuracy guarantee.
- Local vision-language models add RAM/VRAM, startup time, model lifecycle, and container complexity. Hardware on the target server should be measured before selecting a VLM path.

Recommended use: deskew/contrast/orientation preprocessing, multilingual OCR with coordinates, then a deterministic receipt parser. Use a local document VLM only as a second local extraction strategy and retain its output as a proposal, not financial truth.

### 2. Tesseract: lightweight control, not the final parser

Tesseract 5 is Apache-2.0 licensed, supports UTF-8 and more than 100 languages, including Spanish trained data, and returns hOCR/TSV/ALTO output as well as plain text. Its own documentation warns that image-quality improvement is often necessary. It recognizes text; it does not provide a receipt-aware Items array or financial relationships. [Tesseract source and documentation](https://github.com/tesseract-ocr/tesseract)

Advantages are a small operational footprint, mature packages, CPU-only operation, and zero egress. The disadvantage is the largest amount of custom parsing and sensitivity to shadows, thermal-paper contrast, skew, and multi-column layouts. Keep it as a cheap benchmark/control or fallback for simple merchants.

### 3. Azure Receipt container: receipt-specific extraction without image egress

Azure's prebuilt Receipt schema returns an `Items` array containing description, quantity, unit price, and total price, along with merchant, subtotal, tax, tip, and total. The cloud v4.0 model is GA, while the on-premises Receipt container currently uses the v3.1 API family. [Receipt model and output schema](https://learn.microsoft.com/en-us/azure/ai-services/document-intelligence/prebuilt/receipt?view=doc-intel-4.0.0)

Advantages:

- Receipt-specific structured Line Items with confidence and geometry, processed on the local server.
- No custom model training is required for the first trial.
- It is a direct test of whether a specialist model materially reduces review effort.

Constraints:

- Requires an Azure resource, proprietary container images, an x64 Docker deployment, a supporting Read/Layout container, credentials, continuous outbound billing access, and per-page charges.
- The container lags the cloud API generation, increasing upgrade/version-management risk.
- "Spanish OCR supported" is not equivalent to a guarantee for Peruvian merchant abbreviations, PEN formatting, discounts, or a particular receipt layout.

This is the best privacy-preserving specialist to benchmark against PaddleOCR, but it should not be locked in until measured on actual receipts.

### 4. Cloud specialist parsers: opt-in fallback only

**Google Document AI Expense Parser** explicitly supports Spanish and returns currency, total, tax, supplier fields, and Line Item amount/description/product code. Quantity is listed for a release-candidate processor version rather than the base stable field set, so version selection matters. The parser is GA, but availability is regional. [Document AI processor list](https://docs.cloud.google.com/document-ai/docs/processors-list)

Google's listed price is $0.10 for each 1–10 page expense document. Because each photographed receipt is normally its own one-page document, 100 separate receipts are approximately $10, not $1. [Document AI pricing](https://cloud.google.com/document-ai/pricing)

**Azure cloud Receipt** has the clearest complete item schema (description, quantity, unit price, total price) and supports photographed receipts. It sends the image to Azure and retains input/results temporarily as described above. Pricing is per analyzed page and varies by region/offer; Microsoft directs users to its live calculator, and container processing uses the same price as cloud processing. The free F0 tier can be used for a limited trial. [Document Intelligence billing and limits](https://learn.microsoft.com/en-us/azure/ai-services/document-intelligence/service-limits?view=doc-intel-4.0.0) [Azure pricing](https://azure.microsoft.com/en-us/pricing/details/ai-document-intelligence/)

**AWS Textract AnalyzeExpense** returns receipt `LineItemGroups`, normalized `ITEM`, `QUANTITY`, and `PRICE` fields, the complete expense row, geometry, and confidence. [Invoice and receipt response objects](https://docs.aws.amazon.com/textract/latest/dg/expensedocuments.html)

AWS lists AnalyzeExpense at $0.01 per page for the first million pages/month in US West (Oregon), so 100 one-page receipts are approximately $1 before storage/transfer. New AWS customers receive 100 AnalyzeExpense pages/month for three months. Prices vary by Region. [Amazon Textract pricing](https://aws.amazon.com/textract/pricing/)

For this project, AWS has two extra gates: apply and verify the Textract opt-out policy before any personal receipt is sent, and prove Spanish/PEN behavior with the benchmark because the official AnalyzeExpense pages reviewed do not give a Spanish receipt support commitment.

### 5. General-purpose vision models

A general vision-language model—local or cloud—can map unusual layouts directly into a requested JSON shape and may help interpret abbreviations. It should be a fallback after OCR/specialist extraction, not the sole financial parser:

- Schema-shaped output does not prove that values were read correctly.
- Plausible invented or duplicated Line Items can still reconcile poorly or obscure discounts.
- A cloud VLM requires the same explicit image-egress permission as a cloud OCR API.
- A local VLM preserves privacy but adds more compute and model-serving operations than PaddleOCR or Tesseract.

If used, require source bounding boxes or source-text evidence where the model can provide it, preserve the extraction/model version, and subject every result to the same arithmetic and review gates.

## MVP extraction contract and acceptance gates

All engines should sit behind one application-owned adapter and produce the same proposal shape:

- receipt summary: merchant, date/time, currency, subtotal, tax, tip, discounts, and total;
- Line Items: raw description, quantity, unit amount, total amount, optional product code, confidence, and source geometry/text;
- provenance: engine, model/API version, processing mode, and timestamp;
- warnings: unreadable region, unsupported layout, ambiguous decimal/currency, and reconciliation difference.

The adapter output is an **extraction proposal**, never an authoritative mutation. The application remains responsible for monetary parsing, currency rules, category assignment, reconciliation, and approval state.

Acceptance gates:

1. Normalize amounts with explicit PEN/USD currency handling and integer minor units; never use binary floating point for financial equality.
2. Reconcile the receipt's total to the already-known parent Transaction amount within one minor unit. A mismatch always enters review.
3. Reconcile Line Items plus separately represented tax/tip/discount components to the receipt total according to the receipt's tax presentation. Do not silently invent a balancing item.
4. Route low-confidence, missing, duplicated, or arithmetically inconsistent lines to the Receipt Review Queue. Confidence alone must never bypass reconciliation.
5. Preserve the original image locally so the user can correct the breakdown and the pipeline can be re-run after engine upgrades.
6. Count category insights from the reconciled breakdown while retaining one parent Transaction, avoiding double counting.

## Required benchmark before implementation lock-in

Build a private gold set of roughly 30–50 actual receipts, weighted toward the expected workload:

- several Peruvian supermarkets and pharmacies;
- both short and long receipts;
- Spanish abbreviations, PEN and USD examples;
- quantity-by-weight, promotions, discounts, tax-inclusive rows, returns, and duplicate-looking descriptions;
- clean scans plus real phone photos with skew, shadows, folds, and faint thermal paper.

Manually label summary fields and every Line Item. Run PaddleOCR/PP-Structure, Tesseract as a control, and the Azure Receipt container. Run a cloud candidate only if image egress is explicitly approved first. Compare:

- exact amount accuracy for total, each item total, quantity, and unit price;
- Line Item precision/recall, including missed and duplicated rows;
- description character/word error sufficient for categorization;
- percentage of receipts that reconcile without edits;
- median user corrections and review time per receipt;
- latency, peak RAM/VRAM, installation complexity, and per-receipt cost.

The decisive MVP metric should be **reconciled receipts requiring no monetary correction**, followed by review time—not generic OCR accuracy. Select the simplest local option that produces acceptable review effort. If neither local option does, return to the explicit privacy decision before enabling a cloud fallback.

## Resulting constraint on the plan

The MVP specification should preserve a `ReceiptExtractor` boundary and make the provider configurable. It can commit now to local storage, normalized proposals, reconciliation, and human review. It should **not** commit to a permanent OCR vendor or permit receipt-image egress until the target-server benchmark and the separate privacy decision are complete.
