"""Everything the bot says, in one place so it is easy to change."""

WEEKDAYS = ["Даваа", "Мягмар", "Лхагва", "Пүрэв", "Баасан", "Бямба", "Ням"]
TODAY = "Өнөөдөр"
TOMORROW = "Маргааш"
YESTERDAY = "Өчигдөр"

# Quick reply and button titles: at most 20 characters each.
MENU_PLAN = "7 хоногийн ажил"
MENU_OVERDUE = "Хоцорсон ажил"
MENU_MISSING = "Илгээгээгүй"
MENU_HELP = "Тусламж"
YES_SEND = "Тийм, явуул"
YES_UNLINK = "Тийм, салга"
NO = "Болих"

PROFILE_GREETING = "Сайн байна уу! Би «Багш, оюутны туслах» чатбот. Даалгаврын хугацаа, сануулгаа эндээс аваарай."


def not_linked(linkurl: str) -> str:
    return (
        "Сайн байна уу! Би «Багш, оюутны туслах» чатбот.\n\n"
        "Эхлээд Moodle данстайгаа холбоё:\n"
        f"1. Энэ холбоосоор ороод Moodle-д нэвтэр: {linkurl}\n"
        "2. «Connect Messenger» товчийг дар.\n"
        "3. Гарч ирсэн 8 тэмдэгттэй кодыг энд бич."
    )


LINK_OK = "Боллоо, {firstname}! Таны Messenger Moodle данстай холбогдлоо."
LINK_FAILED = (
    "Код буруу эсвэл хугацаа нь дууссан байна. "
    "Moodle-ээс шинэ код аваад дахин бичээрэй: {linkurl}"
)
LINK_TOO_MANY = "Хэт олон удаа код бичлээ. Нэг цагийн дараа дахин оролдоорой."

GREETING = "Сайн байна уу, {firstname}!"
HELP_STUDENT = (
    "Би ингэж тусалж чадна:\n"
    "• «7 хоногийн ажил»: ойрын 7 хоногт хийх даалгавар, сорил\n"
    "• «Хоцорсон ажил»: хугацаа нь өнгөрсөн, хийгээгүй ажил\n"
    "• «салгах»: Messenger-ээ Moodle данснаас салгах"
)
HELP_TEACHER = "\n• «Илгээгээгүй»: даалгавраа илгээгээгүй оюутнууд, тэдэнд сануулга явуулах"
UNKNOWN = "Уучлаарай, үүнийг ойлгосонгүй. Доорх товчнуудаас сонгоно уу."
NON_TEXT = "Би одоогоор зөвхөн бичвэр ойлгоно. Доорх товчнуудаас сонгоно уу."

PLAN_HEADER = "Ойрын 7 хоногт хийх ажил:"
PLAN_EMPTY = "Ойрын 7 хоногт хийх ажил алга. Сайн байна!"
OVERDUE_HEADER = "Хугацаа нь өнгөрсөн ажил:"
OVERDUE_EMPTY = "Хугацаа нь өнгөрсөн ажил алга."
MORE_ITEMS = "…бас {count} ажил байна. Бүгдийг Moodle-ийн Timeline хэсгээс харна уу."

MISSING_EMPTY = "Сүүлийн 30 хоногт илгээгээгүй оюутантай даалгавар алга."
MISSING_HEADER = "Илгээгээгүй оюутантай даалгаврууд:"
MISSING_LINE = "{n}. {name} ({course}): {missing} оюутан, хугацаа {when}"
MISSING_PICK = "Аль даалгаврын оюутнуудад сануулга явуулах вэ?"
SEND_CONFIRM = "«{name}» даалгаврыг илгээгээгүй {missing} оюутанд сануулга явуулах уу?"
SEND_DONE = "{sent} оюутанд сануулга явлаа."
SEND_NONE = "Одоо бүгд илгээсэн байна. Сануулга явуулах хүн алга."
SEND_RECENT = "Энэ даалгаврын сануулгыг саяхан явуулсан. Дахин явуулах бол 10 минут хүлээнэ үү."
SEND_GONE = "Энэ даалгавар жагсаалтад алга. «Илгээгээгүй» гэж бичээд дахин сонгоно уу."
CANCELLED = "За, болиулсан."

# Reports for teachers. They only show data: nothing is sent to students.
INACTIVE_HEADER = "«{course}»: идэвхгүй {count} оюутан (сүүлийн {days} хоногт ороогүй):"
INACTIVE_LINE = "• {student}: {last}, ≈{hours} цаг, илгээгээгүй даалгавар {missing}"
INACTIVE_LINE_NO_HOURS = "• {student}: {last}, илгээгээгүй даалгавар {missing}"
INACTIVE_NEVER = "огт ороогүй"
INACTIVE_DAYS_AGO = "{days} хоногийн өмнө орсон"
INACTIVE_MORE = "…бас {count} оюутан байна."
INACTIVE_HOURS_NOTE = "≈ тэмдэгтэй цаг нь Moodle-ийн бүртгэлээс тооцсон ойролцоо тоо."
INACTIVE_EMPTY = "«{course}»: сүүлийн {days} хоногт бүх оюутан орж ирсэн байна."
INACTIVE_HIDDEN = "Энэ сайт оюутны сүүлд орсон огноог нуудаг тул идэвхгүй оюутныг харуулах боломжгүй."

COURSES_NONE = "Moodle дээр таны заадаг хичээл олдсонгүй."
COURSE_PICK_HEADER = "Таны хичээлүүд:"
COURSE_PICK_LINE = "{n}. {name} ({students} оюутан)"
COURSE_PICK = "Аль хичээлийг харах вэ?"
COURSE_MORE = "…бас {count} хичээл байна."
COURSE_GONE = "Энэ хичээл таны жагсаалтад алга. Дахин сонгоно уу."
QUIZ_PICK_HEADER = "«{course}» хичээлийн сорилууд:"
QUIZ_PICK_LINE = "{n}. {name}: {finished} оюутан өгсөн"
QUIZ_PICK = "Аль сорилын дүнг харах вэ?"
QUIZ_NONE = "«{course}» хичээлд оюутан өгсөн сорил одоогоор алга."
QUIZ_HEADER = "«{quiz}» ({course}): {attempted} оюутан өгсөн, {notattempted} оюутан өгөөгүй."
QUIZ_NO_ATTEMPTS = "«{quiz}» ({course}): одоогоор нэг ч оюутан өгөөгүй байна."
QUIZ_TOPICS_HEADER = "Сэдвүүд, хамгийн сулаас нь:"
QUIZ_TOPIC_LINE = "• {name}: дундаж {average}%"
QUIZ_WEAK_HEADER = "{threshold}%-иас доош сэдэвтэй оюутнууд:"
QUIZ_WEAK_LINE = "• {student}: сорил {percent}%, шалгалтын ойролцоо оноо ≈{estimate}/{points}. Сул сэдэв: {topics}"
QUIZ_WEAK_TOPIC = "{name} {percent}%"
QUIZ_NO_WEAK = "{threshold}%-иас доош сэдэвтэй оюутан алга."
QUIZ_MORE = "…бас {count} оюутан байна."
QUIZ_ESTIMATE_NOTE = "≈ тэмдэгтэй оноо нь сорилын хувиар бодсон ойролцоо тооцоо. Жинхэнэ шалгалтын оноо биш."
QUIZ_UNGRADED_NOTE = "Зарим хариултыг багш хараахан дүгнээгүй байна. Дүгнэсний дараа хувь өөрчлөгдөж магадгүй."

UNLINK_CONFIRM = "Messenger-ээ Moodle данснаас салгах уу? Дараа нь дахин холбож болно."
UNLINK_DONE = "Салгалаа. Дахин холбох бол Moodle-ээс шинэ код аваарай."

ERROR = "Уучлаарай, Moodle-тэй холбогдоход алдаа гарлаа. Хэсэг хугацааны дараа дахин оролдоно уу."
NO_PERMISSION = "Танд үүнийг хийх эрх алга."
ANONYMOUS = "Энэ даалгавар нэргүй горимтой (anonymous) тул сануулга явуулах боломжгүй."
