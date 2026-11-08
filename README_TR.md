# Planlı Yayın (Scheduled Publish)

[English](README.md)

**OJS 3.4.0.x ve 3.5.0.x** için genel (generic) eklenti (referans sürümler: 3.4.0-10 ve 3.5.0-5), iki hat için tek paket. OJS 3.3 için ayrı bir paket (1.x) vardır.

Planlı Yayın, sayıları ve makaleleri planlanan tarih ve saat gelince kendiliğinden yayınlar. Editör zamanı belirler; gerisini OJS yapar, sunucuda cron kurulumu gerekmez.

## Ne yapar

**Sayılar.** Yayınlanmamış sayının formuna (*Sayılar → Gelecek Sayılar → Düzenle*) tarih ve saat alanlı bir *Planlı yayın* bölümü eklenir. Zamanı gelince sayı, bir editör *Sayıyı Yayınla*'ya basıp pencereyi varsayılan ayarlarıyla onaylamış gibi yayınlanır:

- herkese açık tanımlayıcılar (URN vb.) pencerenin atayacağı gibi atanır; sayının DOI'si çekirdeğin yayında yaptığı gibi oluşturulur ve güncellenir;
- gecikmeli açık erişim ilkesi varsa açık erişim tarihi yazılır;
- `IssueGridHandler::publishIssue` kancası çalışır; öteki eklentiler elle yayındaki gibi tepki verir;
- sayı **güncel sayı** olur;
- sayının **Planlandı durumundaki makaleleri yayınlanır**; tarihi boş makale sayının tarihini alır;
- okur bildirimi **OJS'in kendi kuralına** göre gider (pencerenin varsayılanı: açık): abone kullanıcılara kuyruk işleriyle bildirim ve e-posta; e-postanın göndereni sayıyı planlayan kullanıcıdır. Dergide Issue Contributor Notify kurulu ve etkinse, elle yayındaki gibi onun kuralı geçerlidir.

Sayının yayın tarihi, görevin çalıştığı an değil, **planlanan an** olur.

**Makaleler.** İleri bir yayın tarihiyle planlanan makale, sayısı yayınlı olsa da tarihine kadar Planlandı durumunda kalır (OJS 3.4 ve 3.5 tek başına onu hemen yayınlardı); tarihi gelince eklenti yayınlar, ama yalnız sayısı yayınlıysa. Yayınlanmamış sayıdaki makale sayıyı bekler.

**Liste.** Gelecek Sayılar listesine *Planlandı* sütunu gelir: planlanan saat, görev çalışırken *Yayınlanıyor…*, tolerans penceresi geçmişse *Yayınlanmadı!*.

## Saat nasıl işlenir

- Tarih ve saat **OJS kurulumunun saat diliminde** girilir ve saklanır (`config.inc.php` içindeki `time_zone`, yoksa PHP'nin dilimi). Form hangisi olduğunu yazar.
- Görevler **OJS'in kendi görev çalıştırıcısıyla** koşar: OJS 3.4'te acron eklentisi, OJS 3.5'te yerleşik zamanlayıcı (web görev çalıştırıcısı ya da sitenin zaten kullandığı `php lib/pkp/tools/scheduler.php run`), en sık dakikada bir. Planlanan sayı, saatinden sonraki ilk ziyarette, genellikle bir dakika içinde yayınlanır. Cron gerekmez, önerilmez de.
- **Tolerans penceresi** (site ayarı, varsayılan 24 saat): görev geç çalışırsa (ziyaret yok, sunucu kapalı, eklenti kapalı) sayı pencere içinde yine yayınlanır; yayın tarihi planlanan an olur. Daha geç kalınırsa sayı **yayınlanmaz**; form ve liste uyarı gösterir, sayı yeniden planlanabilir ya da elle yayınlanabilir.
- **İptal:** sayı formunda tarihi boşaltıp kaydedin. Sayıyı elle yayınlamak da planı iptal eder.
- **Tek yayın:** sayı üzerindeki kilit, çakışan iki koşunun sayıyı iki kez yayınlamasını engeller. Yarıda kalan koşu 30 dakika sonra yeniden denenir.
- **Çalıştırıcı denetimi:** eklentinin görevleri bir saattir çalışmamışsa (OJS 3.4'te: ya da acron eklentisi kapalıysa) sayı formu ve ayar sayfası uyarır. Kurulumdan hemen sonra, ilk koşuya kadar uyarı görünebilir.

## Gereksinimler

- OJS 3.4.0.x ya da 3.5.0.x; PHP 8.1–8.2. OJS 3.4.0-10 (PHP 8.1) ve 3.5.0-5 (PHP 8.2) üzerinde sınandı.
- OJS 3.4: OJS ile gelen **acron** eklentisi etkin olmalı. OJS 3.5: yerleşik görev çalıştırıcısı (`[schedule] task_runner`) ya da sitenin zaten kullandığı zamanlayıcı cron'u.
- `config.inc.php` değişikliği, veritabanı tablosu, e-posta şablonu gerekmez.

## Kurulum

Site yöneticisi olarak girin, *Web Sitesi → Eklentiler → Yeni eklenti yükle* ile `.tar.gz` paketini yükleyin ve genel eklentiler listesinde **Planlı Yayın**'ı etkinleştirin. Eklenti site genelindedir: bir kez açılır, bütün dergilerde geçerlidir. Tek ayarı (tolerans penceresi) eklentinin *Ayarlar* bağlantısındadır.

**Eklentiyi kapatmadan ya da kaldırmadan önce,** ileri tarihle *Planlandı* durumunda kalan makaleleri ve planı olan sayıları gözden geçirin: eklenti yokken OJS ikisini de yayınlamaz (böyle bir görevi yoktur). Elle yayınlayın ya da tarihleri geçene kadar eklentiyi açık bırakın. Plan, eklenti kapalıyken korunur; eklenti yeniden açılınca tolerans penceresi içindeyse çalışır.

## Kim planlar

Planlama sayı formunun parçasıdır; OJS'in kendi yetkisi geçerlidir: sayıyı düzenleyip yayınlayabilen dergi yöneticileri ve site yöneticileri.

## Günlük

Her koşu OJS'in görev günlüğüne yazar (`files_dir/scheduledTaskLogs/ScheduledPublish*.log`): yayınlanan sayı, planlanan ve gerçekleşen an, kaç makalenin yayınlandığı ya da beklettiği, kaç kullanıcıya bildirim kuyruğa yazıldığı ve başarısız adımlar.

## Lisans

GNU GPL v3. Bkz. [LICENSE](LICENSE).
