package fr.gshz.hideandseek.di

import dagger.Binds
import dagger.Module
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import fr.gshz.hideandseek.data.AccountRepositoryImpl
import fr.gshz.hideandseek.data.ChatRepositoryImpl
import fr.gshz.hideandseek.data.ClientConfigRepositoryImpl
import fr.gshz.hideandseek.data.ConnectionRepositoryImpl
import fr.gshz.hideandseek.data.GameEventRepositoryImpl
import fr.gshz.hideandseek.data.GameRepositoryImpl
import fr.gshz.hideandseek.data.PossibleAreaRepositoryImpl
import fr.gshz.hideandseek.data.LocationRepositoryImpl
import fr.gshz.hideandseek.data.ManualConstraintRepositoryImpl
import fr.gshz.hideandseek.data.QuestionPreviewRepositoryImpl
import fr.gshz.hideandseek.data.QuestionRepositoryImpl
import fr.gshz.hideandseek.data.RoundRepositoryImpl
import fr.gshz.hideandseek.data.SeekerMarkerRepositoryImpl
import fr.gshz.hideandseek.data.SessionRepositoryImpl
import fr.gshz.hideandseek.data.StreetNetworkRepositoryImpl
import fr.gshz.hideandseek.data.TimeTrapRepositoryImpl
import fr.gshz.hideandseek.data.ZoneRepositoryImpl
import fr.gshz.hideandseek.domain.repository.AccountRepository
import fr.gshz.hideandseek.domain.repository.ChatRepository
import fr.gshz.hideandseek.domain.repository.ClientConfigRepository
import fr.gshz.hideandseek.domain.repository.ConnectionRepository
import fr.gshz.hideandseek.domain.repository.GameEventRepository
import fr.gshz.hideandseek.domain.repository.GameRepository
import fr.gshz.hideandseek.domain.repository.PossibleAreaRepository
import fr.gshz.hideandseek.domain.repository.LocationRepository
import fr.gshz.hideandseek.domain.repository.ManualConstraintRepository
import fr.gshz.hideandseek.domain.repository.QuestionPreviewRepository
import fr.gshz.hideandseek.domain.repository.QuestionRepository
import fr.gshz.hideandseek.domain.repository.RoundRepository
import fr.gshz.hideandseek.domain.repository.SeekerMarkerRepository
import fr.gshz.hideandseek.domain.repository.SessionRepository
import fr.gshz.hideandseek.domain.repository.StreetNetworkRepository
import fr.gshz.hideandseek.domain.repository.TimeTrapRepository
import fr.gshz.hideandseek.domain.repository.ZoneRepository
import fr.gshz.hideandseek.feature.map.AndroidTraceImageWriter
import fr.gshz.hideandseek.feature.map.TraceImageWriter
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
abstract class RepositoryModule {

    @Binds
    @Singleton
    abstract fun bindConnectionRepository(impl: ConnectionRepositoryImpl): ConnectionRepository

    @Binds
    @Singleton
    abstract fun bindAccountRepository(impl: AccountRepositoryImpl): AccountRepository

    @Binds
    @Singleton
    abstract fun bindGameRepository(impl: GameRepositoryImpl): GameRepository

    @Binds
    @Singleton
    abstract fun bindPossibleAreaRepository(impl: PossibleAreaRepositoryImpl): PossibleAreaRepository

    @Binds
    @Singleton
    abstract fun bindSessionRepository(impl: SessionRepositoryImpl): SessionRepository

    @Binds
    @Singleton
    abstract fun bindLocationRepository(impl: LocationRepositoryImpl): LocationRepository

    @Binds
    @Singleton
    abstract fun bindQuestionRepository(impl: QuestionRepositoryImpl): QuestionRepository

    @Binds
    @Singleton
    abstract fun bindQuestionPreviewRepository(
        impl: QuestionPreviewRepositoryImpl,
    ): QuestionPreviewRepository

    @Binds
    @Singleton
    abstract fun bindZoneRepository(impl: ZoneRepositoryImpl): ZoneRepository

    @Binds
    @Singleton
    abstract fun bindSeekerMarkerRepository(impl: SeekerMarkerRepositoryImpl): SeekerMarkerRepository

    @Binds
    @Singleton
    abstract fun bindTimeTrapRepository(impl: TimeTrapRepositoryImpl): TimeTrapRepository

    @Binds
    @Singleton
    abstract fun bindStreetNetworkRepository(impl: StreetNetworkRepositoryImpl): StreetNetworkRepository

    @Binds
    @Singleton
    abstract fun bindManualConstraintRepository(
        impl: ManualConstraintRepositoryImpl,
    ): ManualConstraintRepository

    @Binds
    @Singleton
    abstract fun bindRoundRepository(impl: RoundRepositoryImpl): RoundRepository

    @Binds
    @Singleton
    abstract fun bindChatRepository(impl: ChatRepositoryImpl): ChatRepository

    @Binds
    @Singleton
    abstract fun bindGameEventRepository(impl: GameEventRepositoryImpl): GameEventRepository

    @Binds
    @Singleton
    abstract fun bindClientConfigRepository(impl: ClientConfigRepositoryImpl): ClientConfigRepository

    @Binds
    @Singleton
    abstract fun bindTraceImageWriter(impl: AndroidTraceImageWriter): TraceImageWriter
}
