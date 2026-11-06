# Events seed data

Compiled 2026-11-06. Two files:

| File | Rows | Scope |
|---|---|---|
| `nepal-2083.csv` | 91 | BS 2083 (2026-04-14 to 2027-04-13), plus New Year 2084 (2027-04-14) |
| `international.csv` | 105 (85 distinct observances) | 2026-11-01 to 2027-12-31; days in Nov and Dec appear for both years |

Every row in `nepal-2083.csv` starts its `notes` with a quality tag:
- `[official]`: the date is from the MOHA notice.
- `[newspaper citing official]`: a newspaper report of a government decision.
- `[calendar site (2 sites agree)]`: Hamro Patro and rat32 agree.
- `[calendar site (single source)]`: only one site gives the date.

No lunar or festival date was calculated by hand.

## Sources

**Official (primary): MOHA public holiday notice for BS 2083.** Published in the Nepal Gazette, Khanda 75, Sankhya 67, Part 5, dated 2082-11-18 (2026-03-02). The PDF was downloaded from https://moha.gov.np/page/government-and-public-holidays-in-2083 and read page by page; it is a scan with no text layer.
- It gives BS dates and weekdays only, no AD dates.
- The Gazette site (rajpatra.dop.gov.np) refused connections, so the MOHA copy is the one used.

**AD conversion of official dates:**
- The Pradhan & Associates alert (https://www.pradhanlaw.com/file-upload/files/ListofPubl-1773204479.pdf) is a law firm's English version of the same notice, with AD dates.
- The Hamro Patro month pages (`https://www.hamropatro.com/calendar/2083/<month>/`) gave a full BS-to-AD day mapping.
- Pradhan, Hamro Patro and the notice's printed weekdays agree: all 33 AD dates in Pradhan match Hamro Patro, and all 36 weekdays printed in the notice match. Checked by script.

**Festival day names and non-holiday festivals:**
- Hamro Patro, and nepalicalendar.rat32.com (`index.php?year=2083&month=MM`) as a second check.
- I did not use ashesh.com.np. It fetched, but the other two were enough.

**Government decisions after the notice:**
- myRepublica, for the Bakar Eid holiday on 2026-05-28 and the Gen Z Martyrs' Day holiday on 2026-09-08.
- The Kathmandu Post, for the two-day weekend: https://kathmandupost.com/national/2026/04/05/government-announces-two-day-weekend-amid-fuel-crisis.

**International days:**
- UN observances list (https://www.un.org/en/observances/list-days-weeks), with each day's own UN, WHO, FAO or UNESCO page.
- Wikipedia for commercial and other non-UN days. Britannica and timeanddate block automated fetches, so I could not use them.
- Official sites for Safer Internet Day, the World Heart Federation, IASP, UICC, World Emoji Day and International Men's Day.

## Conflicts found

1. **International Women's Day (Falgun 24, 2027-03-08).** Pradhan says the holiday is for women employees only. The official notice (§6.1(च)) lists it under nationwide holidays for everyone. **I used the official notice: `yes`.**
2. **Gaura Parba (Bhadra 19).** Pradhan merges it with Janmashtami as a national holiday. The notice puts it in §2.2, holidays for a specific community or region, without naming the region. I marked it `partial`.
3. **Gen Z Martyrs' Day (Bhadra 23, 2026-09-08).** The notice (§8(ग)) lists it as a national day with offices open. The Cabinet later declared it a nationwide public holiday for 2026 (myRepublica, Spotlight Nepal, ShareSansar). I marked it `yes`. Whether later years get the holiday is unknown.
4. **Bhoto Jatra.** The notice gives no date. Hamro Patro puts it on Asar 6 (2026-06-20). rat32 lists it under Jestha with no day. No MOHA announcement for 2026 was found, so the row is weak.
5. **Kojagrat Purnima.** Both sites list the Kojagrat vrata on Kartik 8 (2026-10-25). rat32 also lists "Kojagrat Purnima" on Kartik 9. I used Kartik 8.
6. **Tharu Guriya.** rat32 puts it on Shrawan 1; Hamro Patro puts it on Bhadra 1. It is not a separate row.
7. **Two-day weekend.** Since 2026-04-06 the government gives offices and schools both Saturday and Sunday off, a fuel-saving measure. Hamro Patro marks every Saturday and Sunday of 2083 as a holiday, but the official notice lists only Saturdays. Weekend days are not separate rows in the CSV. Check whether the policy is still in force before relying on it.

## Weakly sourced rows (verify before use)

- **Eid al-Fitr, 2027-03-10 (Falgun 26): TENTATIVE.** Only rat32 gives it, as "probably Falgun 26". It depends on moon sighting, and MOHA announces the date close to the day.
- **Bhoto Jatra, 2026-06-20:** Hamro Patro only, and it conflicts with rat32 (see above).
- **Sirua Pawani, 2026-04-15 (Baishakh 2):** rat32 only.
- **Chaiti Chhath, 2027-04-12:** rat32 only.
- **Muhammad Jayanti, 2026-08-26:** Hamro Patro. rat32 says "either Bhadra 10 or related day".
- **Nepali New Year 2084, 2027-04-14:** Hamro Patro's day mapping only. The 2084 holiday notice is not out yet, so the holiday status is expected, not confirmed.
- **Hamro Patro only:**
  - National Children's Day (Bhadra 29)
  - the 2072 earthquake remembrance (Baishakh 12)
  - the Bagmati provincial language-day holiday (Baishakh 24)
- **Background-knowledge notes:** a few community notes are general knowledge, not from a source. They are marked "general knowledge" in `notes`: Jitiya (Madhesh/Tharu), Gyalpo Lhosar (Sherpa) and Maghi (the Tharu new year).

## Coverage gaps

- **Ram Navami and Chaite Dashain** do not fall inside BS 2083 on either site. Ram Navami 2026 was in BS 2082; the 2027 one appears to fall in BS 2084, but neither site has 2084 data yet. They are not included.
- **Eid al-Fitr inside BS 2083** happens only once, about March 2027. The tentative row is above.
- **BS 2084 festivals**, for 2027-04-14 onward, are not covered. Neither site has published BS 2084 festival data. This affects `international.csv` too: the dates for Mata Tirtha Aunsi and Kushe Aunsi in 2027 are not sourced, so only the US-rule Mother's Day and Father's Day are listed.
- **Provincial holidays** (up to 6 per province) are not covered systematically; only one Bagmati example appears.
- **Ad-hoc holidays** are not in the files. One example: national mourning for Bhotekoshi flood victims on Bhadra 22 (ekantipur, 2026-09-03).
- **Bank holidays** follow Nepal Rastra Bank's separate list, which was not used.
- **Already past:** rows before 2026-11-06 are kept for completeness. For Bakar Eid and Gen Z Martyrs' Day, the actual observance was confirmed by news reports.
- **International dates:**
  - Commercial dates (Black Friday, Cyber Monday, GivingTuesday, Mother's Day, Earth Hour) were read from Wikipedia infoboxes and checked by calendar arithmetic. Father's Day 2027 was derived from the rule only.
  - Friendship Day uses the UN date (Jul 30). Some markets use the first Sunday of August instead.
