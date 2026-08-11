package fr.gshz.hideandseek.feature.chat

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.ChatMessage
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

internal fun groupMessagesByDate(messages: List<ChatMessage>): List<Pair<LocalDate, List<ChatMessage>>> {
    val result = mutableListOf<Pair<LocalDate, List<ChatMessage>>>()
    var currentDate: LocalDate? = null
    var currentGroup = mutableListOf<ChatMessage>()
    for (message in messages) {
        val msgDate = runCatching {
            OffsetDateTime.parse(message.createdAt)
                .atZoneSameInstant(ZoneId.systemDefault())
                .toLocalDate()
        }.getOrNull() ?: LocalDate.MIN
        if (msgDate != currentDate) {
            if (currentGroup.isNotEmpty() && currentDate != null) {
                result.add(Pair(currentDate, currentGroup.toList()))
            }
            currentDate = msgDate
            currentGroup = mutableListOf()
        }
        currentGroup.add(message)
    }
    if (currentGroup.isNotEmpty() && currentDate != null) {
        result.add(Pair(currentDate, currentGroup.toList()))
    }
    return result
}

@Composable
internal fun formatDateDivider(date: LocalDate): String {
    val today = LocalDate.now()
    return when {
        date == today -> stringResource(R.string.chat_date_today)
        date == today.minusDays(1) -> stringResource(R.string.chat_date_yesterday)
        date.isAfter(today.minusDays(7)) && date.isBefore(today) -> {
            date.format(DateTimeFormatter.ofPattern("EEEE"))
        }
        date.year == today.year -> {
            date.format(DateTimeFormatter.ofPattern("MMMM d"))
        }
        else -> {
            date.format(DateTimeFormatter.ofPattern("MMMM d, yyyy"))
        }
    }
}

@Composable
internal fun DateDividerChip(dateLabel: String) {
    Box(
        modifier = Modifier.fillMaxWidth().padding(vertical = Spacing.xs),
        contentAlignment = Alignment.Center,
    ) {
        Surface(
            shape = RoundedCornerShape(12.dp),
            color = MaterialTheme.colorScheme.surfaceVariant,
        ) {
            Text(
                text = dateLabel,
                modifier = Modifier.padding(horizontal = Spacing.md, vertical = 4.dp),
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}
