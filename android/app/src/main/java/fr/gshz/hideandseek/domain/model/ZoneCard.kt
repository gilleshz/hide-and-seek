package fr.gshz.hideandseek.domain.model

/**
 * The three physical cards that change the hiding zone once the seekers are hunting.
 * The factors are printed on the cards; the server applies them, the client only names the card.
 */
enum class ZoneCard(val wireValue: String) {
    ProsperousHome("prosperous_home"),
    TinyHome("tiny_home"),
    Move("move"),
}
