package fr.gshz.hideandseek.feature.lobby

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.EmojiEvents
import androidx.compose.material.icons.filled.ExpandLess
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.SectionHeader
import fr.gshz.hideandseek.core.ui.formatDurationWords
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.LeaderboardEntry

/** The server ranks the rounds, so a row's position in the list *is* its standing: never re-sort here. */
@Composable
internal fun LeaderboardSection(entries: List<LeaderboardEntry>, modifier: Modifier = Modifier) {
    var expanded by remember { mutableStateOf(false) }
    val shown = if (expanded) entries else entries.take(COLLAPSED_ROWS)

    Column(modifier = modifier, verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        SectionHeader(text = stringResource(R.string.lobby_leaderboard_title), icon = Icons.Filled.EmojiEvents)
        shown.forEachIndexed { index, entry ->
            LeaderboardRow(rank = index + 1, entry = entry)
        }
        if (entries.size > COLLAPSED_ROWS) {
            ExpandToggle(expanded = expanded, onClick = { expanded = !expanded })
        }
    }
}

@Composable
private fun LeaderboardRow(rank: Int, entry: LeaderboardEntry, modifier: Modifier = Modifier) {
    val isBest = rank == 1
    val style = if (isBest) MaterialTheme.typography.titleMedium else MaterialTheme.typography.bodyLarge
    val team = entry.hiderNames
        .takeIf { it.isNotEmpty() }
        ?.joinToString(", ")
        ?: stringResource(R.string.lobby_leaderboard_no_players)

    Surface(
        modifier = modifier.fillMaxWidth(),
        color = if (isBest) {
            MaterialTheme.colorScheme.secondaryContainer
        } else {
            MaterialTheme.colorScheme.surfaceContainer
        },
        contentColor = if (isBest) {
            MaterialTheme.colorScheme.onSecondaryContainer
        } else {
            MaterialTheme.colorScheme.onSurface
        },
        shape = MaterialTheme.shapes.small,
    ) {
        Row(
            modifier = Modifier.padding(horizontal = Spacing.md, vertical = Spacing.sm),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
        ) {
            Text(
                text = stringResource(R.string.lobby_leaderboard_rank, rank),
                style = style,
                textAlign = TextAlign.End,
                modifier = Modifier.width(RANK_COLUMN_WIDTH),
            )
            Text(text = team, style = style, modifier = Modifier.weight(1f))
            Text(text = formatDurationWords(entry.scoreSeconds), style = style)
        }
    }
}

@Composable
private fun ExpandToggle(expanded: Boolean, onClick: () -> Unit, modifier: Modifier = Modifier) {
    TextButton(onClick = onClick, modifier = modifier.fillMaxWidth()) {
        Icon(
            imageVector = if (expanded) Icons.Filled.ExpandLess else Icons.Filled.ExpandMore,
            contentDescription = null,
            modifier = Modifier.size(18.dp),
        )
        Text(
            text = stringResource(
                if (expanded) R.string.lobby_leaderboard_show_less else R.string.lobby_leaderboard_show_all,
            ),
            modifier = Modifier.padding(start = Spacing.xs),
        )
    }
}

private const val COLLAPSED_ROWS = 3
private val RANK_COLUMN_WIDTH = 24.dp

private fun sampleEntries(count: Int): List<LeaderboardEntry> = List(count) { index ->
    LeaderboardEntry(
        roundUuid = "round-$index",
        roundNumber = index + 1,
        hiderNames = if (index == 2) emptyList() else listOf("Alice", "Bob").take(1 + index % 2),
        hidingTimeSeconds = 3600L + index * 300L,
        scoreSeconds = 8040L - index * 600L,
        bonusMinutes = 12,
        bonusPercent = 25,
    )
}

@Preview(showBackground = true)
@Composable
private fun LeaderboardSectionTopThreePreview() {
    AppTheme {
        LeaderboardSection(entries = sampleEntries(3), modifier = Modifier.padding(Spacing.md))
    }
}

@Preview(showBackground = true)
@Composable
private fun LeaderboardSectionExpandablePreview() {
    AppTheme {
        LeaderboardSection(entries = sampleEntries(6), modifier = Modifier.padding(Spacing.md))
    }
}
