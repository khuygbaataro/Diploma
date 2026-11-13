# Багш, оюутны туслах: Messenger бот

Оюутан, багш Facebook Messenger-ээр дамжуулан Moodle-ээс мэдээлэл авдаг бот. Moodle-ийн `local_darimaa` plugin-ий 0.2.0 ба түүнээс шинэ хувилбартай хамт ажиллана.

Техникийн нэр нь `local_darimaa` (plugin), `darimaa-bot` (бот) хэвээр үлдсэн. Хэрэглэгчид зөвхөн «Багш, оюутны туслах» гэсэн нэрийг харна.

**Юу хийдэг вэ:**
- Оюутанд ойрын 7 хоногийн даалгавар, сорил болон хоцорсон ажлыг харуулна.
- Багшид даалгавраа илгээгээгүй оюутнуудыг харуулна. Багш баталсны дараа тэдэнд сануулга явуулна.
- Хүн бүр Moodle-ээс авсан нэг удаагийн кодоор Messenger-ээ Moodle данстайгаа холбоно. Нууц үг чатаар дамжихгүй.

**Хэрхэн ажилладаг вэ:** Messenger → Meta → энэ бот (таны сервер, `127.0.0.1:8100`) → Moodle-ийн REST API → хариу Meta-гаар буцна. Бот, Moodle хоёр нэг сервер дээр ажиллаж болно.

Доорх жишээнүүдэд `ТАНЫ-ДОМЭЙН` гэдгийг Moodle-ийнхоо хаягаар, `SERVER-IP`-ийг серверийнхээ IP хаягаар солино.

## 1. Moodle тал

1. `local_darimaa-0.2.2.zip` файлыг Site administration → Plugins → Install plugins хэсгээр оруулна. Хуучин хувилбар шинэчлэгдэнэ.
2. Сервер дээр ботын токен үүсгэнэ. Moodle-ийн хавтас руу (`config.php` байгаа газар) орж ажиллуулна:

   ```bash
   cd /var/www/moodle
   sudo -u www-data php local/darimaa/cli/setup_bot.php --iprestriction=SERVER-IP
   ```

   Moodle 5.1 ба түүнээс шинэ бол зам нь `public/local/darimaa/cli/setup_bot.php` байна.
   Скрипт Web services-ийг асааж, ботод зориулсан данс, эрх, токен үүсгэнэ. Төгсгөлд нь `MOODLE_URL=...`, `MOODLE_TOKEN=...` гэсэн хоёр мөр гарна. Тэдгээрийг хадгалж аваарай.
3. Хүсвэл Site administration → Plugins → Local plugins → Teacher & Student Assistant хэсгийн «Messenger link» талбарт `https://m.me/таны-page` гэж бичнэ. Тэгвэл оюутанд кодтой нь хамт Messenger нээх товч гарна.

## 2. Ботыг сервер дээр суулгах

Компьютер дээрээ (Windows CMD) ZIP файлаа сервер рүү хуулна:

```bat
scp %USERPROFILE%\Downloads\darimaa-bot-0.2.2.zip moodle@SERVER-IP:~/
```

Сервер дээр:

```bash
sudo apt install -y python3-venv unzip
sudo useradd --system --home /opt/darimaa-bot --shell /usr/sbin/nologin darimaa
sudo unzip ~/darimaa-bot-0.2.2.zip -d /opt/
sudo python3 -m venv /opt/darimaa-bot/venv
sudo /opt/darimaa-bot/venv/bin/pip install -r /opt/darimaa-bot/requirements.txt
sudo cp /opt/darimaa-bot/.env.example /opt/darimaa-bot/.env
sudo nano /opt/darimaa-bot/.env
```

`.env` файлд Moodle-ийн хоёр мөрийг тавина. Meta-гийн утгуудыг 4-р алхмын дараа нөхнө. Дараа нь:

```bash
sudo chown -R darimaa:darimaa /opt/darimaa-bot
sudo chmod 600 /opt/darimaa-bot/.env
sudo cp /opt/darimaa-bot/deploy/darimaa-bot.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now darimaa-bot
curl http://127.0.0.1:8100/healthz
```

Сүүлийн команд `{"ok":true}` гэж хариулбал бот ажиллаж байна.

## 3. Ботыг https хаягаар нээх

Meta зөвхөн https хаяг руу мессеж илгээдэг. Тиймээс Moodle-ийн https хаягийн `/darimaa-bot/` замыг бот руу дамжуулна.

**Apache:**

```bash
sudo a2enmod proxy proxy_http
sudo nano /etc/apache2/sites-available/moodle-ssl.conf
```

Файлын нэр өөр байж болно. Moodle-ийн `<VirtualHost *:443>` хэсгийн дотор `deploy/apache-darimaa-bot.conf`-ийн хоёр мөрийг нэмнэ. Дараа нь:

```bash
sudo apache2ctl configtest && sudo systemctl reload apache2
```

**Nginx:** `deploy/nginx-darimaa-bot.conf`-ийн `location` хэсгийг Moodle-ийн `server { listen 443 ... }` дотор нэмээд `sudo nginx -t && sudo systemctl reload nginx` гэж ажиллуулна.

Шалгах: `curl https://ТАНЫ-ДОМЭЙН/darimaa-bot/healthz` нь `{"ok":true}` гэж хариулах ёстой.

Moodle чинь https-гүй, эсвэл зөвхөн IP хаягаар нээгддэг бол эхлээд домэйн холбож, Let's Encrypt (`certbot`)-ээр https тохируулна.

## 4. Meta тал

1. Туршилтад зориулж Facebook Page нээнэ, эсвэл байгаа Page-ээ ашиглана. Тэр Page-д ManyChat зэрэг өөр бот холбоотой бол туршилтад шинэ Page нээх нь дээр.
2. [developers.facebook.com](https://developers.facebook.com/apps) → Create app. Messenger-ийн хэрэглээг сонгоно («Engage with customers on Messenger from Meta»).
3. App settings → Basic хэсгээс App secret-ийг хуулж `.env`-ийн `FB_APP_SECRET`-д тавина.
4. Messenger API settings хэсэгт:
   - Webhook: Callback URL нь `https://ТАНЫ-ДОМЭЙН/darimaa-bot/webhook`. Verify token-д `.env`-ийн `FB_VERIFY_TOKEN`-тэй яг ижил текст бичнэ. «Verify and save» дарна.
   - Page-ээ холбоод access token үүсгэнэ. Түүнийг `FB_PAGE_ACCESS_TOKEN`-д тавина.
   - Page-ийн webhook-д `messages`, `messaging_postbacks`, `messaging_referrals`-ийг сонгож subscribe хийнэ.
5. `.env`-ээ шинэчилсний дараа `sudo systemctl restart darimaa-bot` гэж ажиллуулна.
6. Get Started товч, мэндчилгээ, цэсийг нэг удаа тохируулна:

   ```bash
   cd /opt/darimaa-bot && sudo -u darimaa venv/bin/python -m darimaa_bot.setup_profile
   ```

7. Туршилтын үед бот зөвхөн app-д эрхтэй хүмүүст (admin, developer, tester) хариулна. App roles → Roles хэсэгт туршиж үзэх хүмүүсээ нэмнэ. Бүх оюутанд нээхдээ app-аа Live горимд шилжүүлнэ. Meta-гийн баримт бичигт өөрийн Page-ийн мессеж илгээх, хүлээн авахад App Review хэрэггүй гэж бичсэн. Харин Business Verification шаардах эсэхийг тэр үед Meta-гийн самбар дээрээс шалгана.

## 5. Туршиж үзэх

1. Оюутны эрхээр Moodle-д нэвтэрч Preferences → Messenger connection руу орно. Эсвэл `https://ТАНЫ-ДОМЭЙН/local/darimaa/link.php` хаягийг шууд нээнэ. «Connect Messenger» товч дарна.
2. Гарч ирсэн кодыг Page руу Messenger-ээр бичнэ. Бот «Боллоо, ...!» гэж хариулна.
3. «7 хоногийн ажил» товч дарна.
4. Багшийн эрхээр мөн адил холбоод «Илгээгээгүй» товч дарна.

## Асуудал гарвал

- Ботын лог: `sudo journalctl -u darimaa-bot -f`
- Логт `Invalid token - IP:x.x.x.x` гарвал `setup_bot.php`-ийг `--iprestriction=x.x.x.x` (логт гарсан IP)-ээр дахин ажиллуулна.
- Webhook verify амжилтгүй бол `FB_VERIFY_TOKEN` хоёр талд ижил эсэх, `https://ТАНЫ-ДОМЭЙН/darimaa-bot/healthz` нээгдэж байгаа эсэхийг шалгана.
- Логт `Refused a webhook call with a wrong signature` гарвал `FB_APP_SECRET` буруу байна.
- Бот хариулахгүй бол Page-ийн webhook subscribe хийгдсэн эсэх, бичиж байгаа хүн app-д tester эрхтэй эсэхийг шалгана.

## Өөрчлөлт

### 0.2.2 (2026-11-13)

Шинэ боломж нэмээгүй. 0.2.1-ийн шалгалтаар олдсон жижиг алдаануудыг зассан.

- Page-ийн access token-ийг хаягт (URL) биш, `Authorization` толгойд хийж илгээдэг болсон. Өмнө нь токен логт бичигддэг байсан. Бот өмнө нь ажиллаж байсан бол Meta дээр Page-ийн токеноо шинээр үүсгээд `.env`-д солино уу.
- Логт Messenger-ийн хэрэглэгчийн ID бичигдэхгүй. Оронд нь түүний 12 тэмдэгттэй богино хэш бичигдэнэ.
- Санаандгүй алдаа гарвал хэрэглэгч хариугүй үлдэхгүй. Бот алдааг логт бичээд «алдаа гарлаа» гэсэн хариу илгээнэ.
- `tzdata` санг `requirements.txt`-д нэмсэн. Windows дээр цагийн бүсийн мэдээлэл байдаггүй тул өмнө нь тест ажилладаггүй байсан.
- Plugin 0.2.2-т `local_darimaa_get_user` функц хэрэглэгчийн ID, бүтэн нэр, хэлийг буцаахаа больсон. Бот эдгээрийг ашигладаггүй тул plugin-ий 0.2.0, 0.2.1 хувилбартай ч хэвээр ажиллана.

Шинэчлэхдээ шинэ файлуудаа `/opt/darimaa-bot` руу хуулаад дараах хоёр мөрийг ажиллуулна:

```bash
sudo /opt/darimaa-bot/venv/bin/pip install -r /opt/darimaa-bot/requirements.txt
sudo systemctl restart darimaa-bot
```

## Хөгжүүлэгчид

- Тест ажиллуулах: `pip install -r requirements-dev.txt && pytest`
- Ботын бүх текст `darimaa_bot/texts.py`-д, яриа удирдах логик `darimaa_bot/conversation.py`-д байна.
- Moodle-ийн функцүүд: `local_darimaa_get_user`, `local_darimaa_link_account`, `local_darimaa_unlink_account`, `local_darimaa_get_deadlines`, `local_darimaa_get_missing`, `local_darimaa_send_reminders`.

Эх сурвалж: [Messenger Platform overview](https://developers.facebook.com/docs/messenger-platform/overview)
