package fr.gshz.hideandseek.feature.question

import androidx.compose.runtime.Composable
import androidx.compose.ui.res.stringResource
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.cardEconomy

@Composable
internal fun questionCardsText(category: QuestionCategory, repeatCount: Int = 1): String {
    val (draw, keep) = cardEconomy.getValue(category)
    return when {
        repeatCount > 1 -> stringResource(R.string.chat_cards_line_repeat, draw, keep, repeatCount)
        draw == 1 -> stringResource(R.string.chat_cards_line_single)
        else -> stringResource(R.string.chat_cards_line, draw, keep)
    }
}
