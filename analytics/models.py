from django.db import models


class DimIit(models.Model):
    iit_id       = models.SmallIntegerField(primary_key=True)
    iit_name     = models.CharField(max_length=120)
    short_code   = models.CharField(max_length=20, null=True, blank=True)
    founded_year = models.SmallIntegerField(null=True, blank=True)
    generation   = models.CharField(max_length=3)  # 'old' or 'new'

    class Meta:
        managed = False
        db_table = 'dim_iit'

    def __str__(self):
        return self.iit_name


class DimBranch(models.Model):
    branch_id   = models.IntegerField(primary_key=True)
    branch_name = models.CharField(max_length=255)
    category    = models.CharField(max_length=20)

    class Meta:
        managed = False
        db_table = 'dim_branch'

    def __str__(self):
        return self.branch_name


class DimQuota(models.Model):
    quota_id   = models.SmallIntegerField(primary_key=True)
    quota_code = models.CharField(max_length=20)

    class Meta:
        managed = False
        db_table = 'dim_quota'

    def __str__(self):
        return self.quota_code


class DimSeatType(models.Model):
    seat_type_id   = models.SmallIntegerField(primary_key=True)
    seat_type_code = models.CharField(max_length=30)

    class Meta:
        managed = False
        db_table = 'dim_seat_type'

    def __str__(self):
        return self.seat_type_code


class DimGender(models.Model):
    gender_id   = models.SmallIntegerField(primary_key=True)
    gender_code = models.CharField(max_length=60)

    class Meta:
        managed = False
        db_table = 'dim_gender'

    def __str__(self):
        return self.gender_code


class FactAllotment(models.Model):
    id           = models.BigIntegerField(primary_key=True)
    iit          = models.ForeignKey(DimIit, db_column='iit_id', on_delete=models.DO_NOTHING)
    branch       = models.ForeignKey(DimBranch, db_column='branch_id', on_delete=models.DO_NOTHING)
    quota        = models.ForeignKey(DimQuota, db_column='quota_id', on_delete=models.DO_NOTHING)
    seat_type    = models.ForeignKey(DimSeatType, db_column='seat_type_id', on_delete=models.DO_NOTHING)
    gender       = models.ForeignKey(DimGender, db_column='gender_id', on_delete=models.DO_NOTHING)
    year         = models.SmallIntegerField()
    round_no     = models.SmallIntegerField()
    opening_rank = models.IntegerField()
    closing_rank = models.IntegerField()
    is_preparatory = models.SmallIntegerField(default=0)

    class Meta:
        managed = False
        db_table = 'fact_allotment'


class AiQuery(models.Model):
    id               = models.BigAutoField(primary_key=True)
    cache_key        = models.CharField(max_length=64, unique=True)
    question         = models.TextField()
    response_json    = models.TextField()
    hit_count        = models.IntegerField(default=0)
    created_at       = models.DateTimeField(auto_now_add=True)
    last_accessed_at = models.DateTimeField(auto_now=True)

    class Meta:
        managed = False
        db_table = 'ai_queries'

    def __str__(self):
        return self.question[:60]
