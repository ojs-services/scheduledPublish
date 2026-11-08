# Planlı Yayın (Scheduled Publish)

[English](README.md)

**OJS 3.3.0.x** için genel (generic) eklenti (referans sürüm: 3.3.0-22). OJS 3.4 ve 3.5 için ayrı bir paket vardır.

Planlı Yayın, sayıları ve makaleleri planlanan tarih ve saat gelince kendiliğinden yayınlar. Editör zamanı belirler; gerisini OJS yapar, sunucuda cron kurulumu gerekmez.

## Ne yapar

**Sayılar.** Yayınlanmamış sayının formuna (*Sayılar → Gelecek Sayılar → Düzenle*) tarih ve saat alanlı bir *Planlı yayın* bölümü eklenir. Zamanı gelince sayı, bir editör *Sayıyı Yayınla*'ya basıp pencereyi varsayılan ayarlarıyla onaylamış gibi yayınlanır:

- herkese açık tanımlayıcılar (DOI, URN) pencerenin atayacağı gibi atanır;
- gecikmeli açık erişim ilkesi varsa açık erişim tarihi yazılır;
- `IssueGridHandler::publishIssue` kancası çalışır; öteki eklentiler elle yayındaki gibi tepki verir;
- sayı **güncel sayı** olur;
- sayının **Planlandı durumundaki makaleleri yayınlanır**; tarihi boş makale sayının tarihini alır;
- okur bildirimi **OJS'in kendi kuralına** göre gider (pencerenin varsayılanı: açık). Dergide Issue Contributor Notify kurulu ve etkinse, elle yayındaki gibi onun kuralı geçerlidir.

Sayının yayın tarihi, görevin çalıştığı an değil, **planlanan an** olur.

**Makaleler.** İleri bir yayın tarihiyle planlanan makale, tarihi gelince yayınlanır; ama yalnız sayısı yayınlıysa. Yayınlanmamış sayıdaki makale sayıyı bekler; tarihi sayının yayınından sonra olan makale sayıyla birlikte çıkmaz, kendi tarihinde yayınlanır.

**Liste.** Gelecek Sayılar listesine *Planlandı* sütunu gelir: planlanan saat, görev çalışırken *Yayınlanıyor…*, tolerans penceresi geçmişse *Yayınlanmadı!*.

## Saat nasıl işlenir

- Tarih ve saat **OJS kurulumunun saat diliminde** girilir ve saklanır (`config.inc.php` içindeki `time_zone`, yoksa PHP'nin dilimi). Form hangisi olduğunu yazar.
- Görevler **OJS'in kendi görev çalıştırıcısıyla** (acron eklentisi), sayfa isteklerinin sonunda, en sık dakikada bir koşar. Planlanan sayı, saatinden sonraki ilk ziyarette, genellikle bir dakika içinde yayınlanır. Cron gerekmez, önerilmez de.
- **Tolerans penceresi** (site ayarı, varsayılan 24 saat): görev geç çalışırsa (ziyaret yok, sunucu kapalı, eklenti kapalı) sayı pencere içinde yine yayınlanır; yayın tarihi planlanan an olur. Daha geç kalınırsa sayı **yayınlanmaz**; form ve liste uyarı gösterir, sayı yeniden planlanabilir ya da elle yayınlanabilir.
- **İptal:** sayı formunda tarihi boşaltıp kaydedin. Sayıyı elle yayınlamak da planı iptal eder.
- **Tek yayın:** sayı üzerindeki kilit, çakışan iki koşunun sayıyı iki kez yayınlamasını engeller. Yarıda kalan koşu 30 dakika sonra yeniden denenir.

## Gereksinimler

- OJS 3.3.0.x; PHP 7.4–8.2. OJS 3.3.0-22 üzerinde PHP 7.4, 8.1 ve 8.2 ile sınandı.
- OJS ile gelen **acron** eklentisi etkin olmalı: OJS'in görev çalıştırıcısı odur.
- `config.inc.php` değişikliği, veritabanı tablosu, e-posta şablonu gerekmez.

## Kurulum

Site yöneticisi olarak girin, *Web Sitesi → Eklentiler → Yeni eklenti yükle* ile `.tar.gz` paketini yükleyin ve genel eklentiler listesinde **Planlı Yayın**'ı etkinleştirin. Eklenti site genelindedir: bir kez açılır, bütün dergilerde geçerlidir. Tek ayarı (tolerans penceresi) eklentinin *Ayarlar* bağlantısındadır.

1.1.0.0'dan yükseltme: yeni paketi eskisinin üzerine yükleyin. Var olan davranış (tarihi gelen makalelerin yayınlanması) sürer; yeni görevler yükseltmeden sonraki ilk sayfa isteğinde görev listesine girer.

## Kim planlar

Planlama sayı formunun parçasıdır; OJS'in kendi yetkisi geçerlidir: sayıyı düzenleyip yayınlayabilen dergi yöneticileri (ve site yöneticileri).

## Günlük

Her koşu OJS'in görev günlüğüne yazar (`files_dir/scheduledTaskLogs/ScheduledPublish*.log`): yayınlanan sayı, planlanan ve gerçekleşen an, kaç makalenin yayınlandığı ya da beklettiği, kaç kullanıcıya bildirim gittiği ve başarısız adımlar.

## Lisans

GNU GPL v3. Bkz. [LICENSE](LICENSE).
