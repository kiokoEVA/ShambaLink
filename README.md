# A near real-time web-based transport matching system for Kenya

PHP 8 + MySQL + Bootstrap 5 + AJAX polling + Africa's Talking SMS. Built for XAMPP.

## Set up (5 minutes)
1. Copy this folder to `C:\xampp\htdocs\shambalink` (replace your existing stub files).
2. Start **Apache** and **MySQL** in the XAMPP control panel.
3. Open phpMyAdmin, choose **Import**, and import `database.sql`.
4. Visit http://localhost/shambalink/install.php once to create the admin and demo accounts, then **delete install.php**.
5. Open http://localhost/shambalink/

If your MySQL root user has a password, edit `config/db.php`.

## Demo logins (phone / password)
| Role | Phone | Password |
|---|---|---|
| Admin | 0700000000 | Admin@12345 | 
| Farmer | 0711000001 | Password123 |
| Transporter | 0722000001 to 0722000004 | Password123 |

## Try the manual matching flow
1. Log in as the farmer, post a load (e.g. Tomatoes, 4200 kg, Mlolongo to Wakulima Market, pickup today, deliver by tomorrow).
2. Choose **Find transporters**. Ranked trucks appear with score breakdowns. Request one.
3. In a second browser (or private window) log in as that transporter. The request appears within about 8 seconds without a refresh. Accept it.
4. The farmer's page updates on its own. Transporter then uses **Start trip** and **Mark as delivered**.
5. Log in as admin to see stats, the average time to match, and the SMS log.

## How it maps to your proposal
| Proposal | Where |
|---|---|
| Rule-based weighted matching (proximity, capacity, availability, timeline) | `includes/functions.php`, `score_match()` and `rank_offers()`; weights are constants at the top |
| AJAX polling every 5 to 10 s | `js/app.js` and `api/poll.php` (interval in `config/db.php`) |
| Farmer, transporter, admin roles | `auth.php`, `register.php`, `login.php`, three dashboards |
| Load and offer posting | `farmer_dashboard.php`, `transporter_dashboard.php`, `includes/actions.php` |
| Match confirmation and manual trip status | `includes/actions.php` (accept, decline, trip) |
| SMS via Africa's Talking | `send_sms()`; set `AT_API_KEY` in `config/db.php` (empty means messages are only logged) |
| Admin reporting | `admin_dashboard.php` |
| Test cases TC001 to TC007 | register, post load, post vehicle, ranking, polling, SMS log, failed login |

## Notes for your write-up
- The scoring formulas are documented in comments inside `score_match()`. Adjust the wording of the delivery-timeline rule to match your Chapter 4 if it differs.
- Matching is manual (users choose and confirm), but candidates are ranked by the engine.
- Distances use a seeded table of Kenyan towns (straight-line distance x 1.25), not live GPS or maps.
