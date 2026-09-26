=== Sameday WooCommerce България ===
Contributors: sameday-woocommerce-bg
Tags: woocommerce, shipping, sameday, easybox, courier, delivery, bulgaria
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.7.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Доставка със Sameday за WooCommerce - EasyBox и Куриер 24 часа. Без API интеграция.

== Description ==

Този плъгин добавя два метода за доставка със Sameday към вашата WooCommerce витрина:

1. **Sameday EasyBox** - Доставка до автомат на Sameday
2. **Sameday Куриер 24 часа** - Стандартна куриерска доставка

Плъгинът не изисква API ключове или външни връзки към Sameday. Всички настройки се управляват локално от администратора.

== Features ==

- Доставка до EasyBox автомат с избор на град и автомат
- Стандартна куриерска доставка
- Автоматично изчисление на цена според тегло
- Поддръжка на наложен платеж (COD)
- Управление на локации на автомати
- Пълна локализация на български език
- Съвместим с WordPress 5.8+ и WooCommerce 5.0+

== Installation ==

1. Свалете плъгина като ZIP файл
2. Влезте в админ панела на WordPress
3. Отидете в Plugins > Add New
4. Кликнете "Upload Plugin" и изберете сваления ZIP файл
5. Кликнете "Install Now" и след това "Activate Plugin"

== Настройки ==

След активиране, отидете в WooCommerce > Настройки > Доставка, за да конфигурирате методите за доставка.

За управление на локациите на EasyBox автомати, отидете в WooCommerce > EasyBox Локации.

== Ценова политика ==

По подразбиране са зададени следните цени:

**Sameday EasyBox:**
- 2.90 лв за първите 3 кг
- +0.40 лв за всеки допълнителен кг

**Sameday Куриер 24 часа:**
- 5.90 лв за първите 3 кг
- +0.40 лв за всеки допълнителен кг

**Наложен платеж (COD):**
- 1% от стойността на поръчката
- минимум 0.50 лв

Цените могат да бъдат променяни от администратора.

== Чести въпроси ==

= Изисква ли този плъгин API ключ от Sameday? =

Не. Този плъгин не изисква API ключове или външни връзки към Sameday. Всички данни се управляват локално.

= Как се добавят нови EasyBox автомати? =

От админ панела, отидете в WooCommerce > EasyBox Локации и използвайте формата за добавяне на нови локации.

= Работи ли плъгинът с наложен платеж? =

Да. Плъгинът поддържа наложен платеж с автоматично изчисление на таксата.

== Changelog ==

= 1.7.0 =
* Безплатната доставка вече никога не важи за международните пратки с A1POST - те се таксуват по зоновата тарифа независимо от сумата на поръчката.
* Правилото за безплатна доставка над праг важи само за държавите в новата настройка "За кои държави важи" (по подразбиране само България).
* Нова настройка "За кои методи на плащане важи": само карта (по подразбиране) или всички методи, включително наложен платеж.
* Списъкът с офиси и АПС на Speedy се тегли от Speedy API с една заявка. Публичните страници на Speedy остават резервен източник.
* Търсенето на населени места вече не блокира checkout-а: заявките към куриера имат кратък таймаут, а след неуспех влизат в 15-минутна пауза и се пренасрочват във фонов режим.
* Търсенето на офиси и автомати вече не се проваля при кеширан checkout заради изтекъл nonce.
* Празният списък с населени места вече обяснява причината вместо да остава празен.
* Дневник на изпратените имейли в WooCommerce > Имейл диагностика.

= 1.6.3 =
* Added visible Speedy pickup date field when generating a shipment.
* Stores and shows the pickup date used for Speedy shipments, so profile date filtering is easier to diagnose.

= 1.6.2 =
* Added dedicated A1POST recipient and address fields in checkout for stores that hide WooCommerce address fields.
* Saves the A1POST address to the order and uses it when creating labels.
* Keeps A1POST price visible while validating the address fields at order placement.

= 1.6.1 =
* Added direct A1POST API integration for international WooCommerce orders.
* Added A1POST label create, print and delete actions in the order admin screen.
* Auto-selects A1POST when it is the only available checkout delivery option outside Bulgaria.

= 1.6.0 =
* Безплатна доставка при плащане с карта над зададен праг (по подразбиране 49.99). Правилото важи и за доставка до адрес и се отразява веднага в checkout, включително в общата сума.
* Куриерите се ограничават по държава: Sameday и Speedy се показват само за адреси в България, A1POST - само за адреси извън България. Изборът на доставка се презарежда автоматично при смяна на държавата.
* Добавен куриер A1POST за международни доставки с тарифа по зони (WooCommerce > A1POST).
* Нов екран за диагностика на имейлите за поръчки (WooCommerce > Имейл диагностика).

= 1.0.0 =
* Първоначално издаване

== Upgrade Notice ==

= 1.6.0 =
След обновяване проверете WooCommerce > Sameday за прага за безплатна доставка при карта и WooCommerce > A1POST за тарифата за чужбина.

= 1.0.0 =
Първоначално издаване на плъгина за доставка със Sameday.
